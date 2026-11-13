import 'package:yomara_erp/ui/widgets/adaptive_sheet.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/evenements.dart';
import '../../core/local_db.dart';
import '../../core/sync_engine.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/filter_bar.dart';
import 'document_editor.dart';
import 'documents_screen.dart' show DocConfig;
import 'attendance_screen.dart' show AttendanceView;
import 'billetterie_screen.dart' show TicketingTab, exhibitorBadges;
import 'event_extras.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

import 'package:yomara_erp/core/safe_launch.dart';
const _palette = ['#1F6FEB', '#12A150', '#8E44AD', '#E8A317', '#D93025', '#0FA3B1', '#FF7A00', '#5B6B85'];

Color evColor(dynamic hex, [Color fallback = YColors.blue]) {
  final h = '${hex ?? ''}'.replaceAll('#', '');
  if (h.length != 6) return fallback;
  final v = int.tryParse(h, radix: 16);
  return v == null ? fallback : Color(0xFF000000 | v);
}

String _n(dynamic v) {
  if (v == null || '$v'.isEmpty) return '';
  final d = toDouble(v);
  return d == d.roundToDouble() ? '${d.toInt()}' : '$d';
}

Color _statusColor(String s) => switch (s) {
      'confirmed' => YColors.success,
      'option' => YColors.warning,
      'cancelled' => YColors.danger,
      'expired' => YColors.muted,
      _ => YColors.blue,
    };

bool _canManage(Session s) => s.can('evenements.manage');
bool _canSell(Session s) => s.can('evenements.sell') || s.can('evenements.manage');

/// Liste des événements (salons, foires, festivals) avec leur remplissage.
class EvenementsScreen extends StatefulWidget {
  const EvenementsScreen({super.key});

  @override
  State<EvenementsScreen> createState() => _EvenementsScreenState();
}

class _EvenementsScreenState extends State<EvenementsScreen> {
  String _q = '';
  int _rk = 0;
  String _status = '';
  Map<String, String?> _f = {};
  FilterPeriod? _period;

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final narrow = MediaQuery.of(context).size.width < 700;
    return Column(children: [
      PageHeader(
        title: tr('Événements & salons'),
        subtitle: tr('Plan des stands, réservations des exposants, sponsors et facturation'),
        actions: [if (_canManage(session)) FilledButton.icon(onPressed: () => editEvent(context), icon: const Icon(Icons.add), label: Text(tr('Nouvel événement')))],
      ),
      Expanded(
        child: ValueListenableBuilder(
          valueListenable: LocalDb.listen('ev_events'),
          builder: (context, _, _) => ValueListenableBuilder(
            valueListenable: LocalDb.listen('ev_bookings'),
            builder: (context, _, _) {
              final events = LocalDb.all('ev_events')..sort((a, b) => '${b['start_date']}'.compareTo('${a['start_date']}'));
              if (events.isEmpty) {
                return EmptyState(icon: Icons.storefront_outlined, title: tr('Aucun événement'), message: tr('Créez votre salon, dessinez le plan des stands et vendez les emplacements aux exposants.'));
              }
              final q = _q.toLowerCase();
              final base = events
                  .where((e) => q.isEmpty || '${e['name'] ?? ''} ${e['venue'] ?? ''} ${e['city'] ?? ''}'.toLowerCase().contains(q))
                  .where((e) => _f['city'] == null || '${e['city'] ?? ''}' == _f['city'])
                  .where((e) => _f['venue'] == null || '${e['venue'] ?? ''}' == _f['venue'])
                  .where((e) => _period == null || _period!.contains(e['start_date']) || _period!.contains(e['end_date']))
                  .toList();
              final shown = base.where((e) => _status.isEmpty || '${e['status']}' == _status).toList();
              return Column(children: [
                FilterBar(
                  key: ValueKey(_rk),
                  onSearch: (v) => setState(() => _q = v),
                  search: _q,
                  searchHint: 'Nom, lieu, ville…',
                  statuses: {'': 'Tous', ...evEventLabels},
                  status: _status,
                  onStatus: (v) => setState(() => _status = v),
                  statusCounts: {'': base.length, for (final k in evEventLabels.keys) k: base.where((e) => '${e['status']}' == k).length},
                  filters: [
                    FilterSpec('city', 'Ville', filterOptions(events, 'city'), icon: Icons.location_city_outlined),
                    FilterSpec('venue', 'Lieu', filterOptions(events, 'venue'), icon: Icons.place_outlined),
                  ],
                  values: _f,
                  onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
                  showPeriod: true,
                  period: _period,
                  onPeriod: (p) => setState(() => _period = p),
                  count: shown.length,
                  countLabel: 'événement',
                  onReset: () => setState(() => (_rk++, _q = '', _status = '', _f = {}, _period = null)),
                ),
                Expanded(
                  child: shown.isEmpty
                      ? EmptyState(icon: Icons.filter_alt_off_outlined, title: tr('Aucun événement ne correspond aux filtres'))
                      : ListView(padding: EdgeInsets.fromLTRB(narrow ? 16 : 24, 0, narrow ? 16 : 24, 24), children: [
                          for (final e in shown) _EventCard(event: e, currency: '${e['currency'] ?? session.currency}'),
                        ]),
                ),
              ]);
            },
          ),
        ),
      ),
    ]);
  }
}

class _EventCard extends StatelessWidget {
  const _EventCard({required this.event, required this.currency});

  final Map<String, dynamic> event;
  final String currency;

  @override
  Widget build(BuildContext context) {
    final st = evStats(event);
    final hint = Theme.of(context).hintColor;
    final status = '${event['status']}';
    return Card(
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => EventDetailScreen(eventId: '${event['id']}'))),
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Row(children: [
            Container(
              width: 56,
              padding: const EdgeInsets.symmetric(vertical: 8),
              decoration: BoxDecoration(color: YColors.night, borderRadius: BorderRadius.circular(10)),
              child: Column(children: [
                Text(tr(dateFr(event['start_date']).substring(0, 2)), style: const TextStyle(color: Colors.white, fontSize: 20, fontWeight: FontWeight.w800)),
                Text(tr(_month(event['start_date'])), style: const TextStyle(color: YColors.yellow, fontSize: 11, fontWeight: FontWeight.w700)),
              ]),
            ),
            const SizedBox(width: 14),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Row(children: [
                  Flexible(child: Text(tr('${event['name']}'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16), overflow: TextOverflow.ellipsis)),
                  const SizedBox(width: 8),
                  StatusChip(evEventLabels[status] ?? status, color: status == 'open' ? YColors.success : (status == 'cancelled' ? YColors.danger : YColors.muted)),
                ]),
                const SizedBox(height: 2),
                Text(tr([
                  '${dateFr(event['start_date'])}${event['end_date'] != event['start_date'] ? ' au ${dateFr(event['end_date'])}' : ''}',
                  if (event['venue'] != null) '${event['venue']}',
                  if (event['city'] != null) '${event['city']}',
                ].join(' · ')), style: TextStyle(color: hint)),
                const SizedBox(height: 8),
                ClipRRect(
                  borderRadius: BorderRadius.circular(6),
                  child: SizedBox(
                    height: 8,
                    child: Row(children: [
                      if (st.confirmed > 0) Expanded(flex: st.confirmed, child: Container(color: YColors.success)),
                      if (st.option > 0) Expanded(flex: st.option, child: Container(color: YColors.warning)),
                      if (st.free > 0) Expanded(flex: st.free, child: Container(color: YColors.line)),
                      if (st.sellable == 0) Expanded(child: Container(color: YColors.line)),
                    ]),
                  ),
                ),
                const SizedBox(height: 6),
                Text(tr('${st.confirmed} confirmé(s) · ${st.option} en option · ${st.free} libre(s) sur ${st.sellable} — ${st.occupancy.toStringAsFixed(0)} % · ${money(st.revenueConfirmed, currency)} HT confirmés'),
                    style: TextStyle(fontSize: 12.5, color: hint)),
              ]),
            ),
            const Icon(Icons.chevron_right),
          ]),
        ),
      ),
    );
  }

  static String _month(dynamic d) {
    const m = ['JAN', 'FÉV', 'MAR', 'AVR', 'MAI', 'JUIN', 'JUIL', 'AOÛT', 'SEPT', 'OCT', 'NOV', 'DÉC'];
    final dt = DateTime.tryParse('$d');
    return dt == null ? '' : '${m[dt.month - 1]} ${dt.year % 100}';
  }
}

// ======================================================================
// Détail d'un événement
// ======================================================================

class EventDetailScreen extends StatefulWidget {
  const EventDetailScreen({super.key, required this.eventId});

  final String eventId;

  @override
  State<EventDetailScreen> createState() => _EventDetailScreenState();
}

class _EventDetailScreenState extends State<EventDetailScreen> {
  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('ev_events'),
      builder: (context, _, _) {
        final event = LocalDb.get('ev_events', widget.eventId);
        if (event == null) return Scaffold(appBar: AppBar(), body: EmptyState(icon: Icons.storefront_outlined, title: tr('Événement introuvable')));
        final ticketing = session.hasModule('billetterie');
        return DefaultTabController(
          length: ticketing ? 7 : 4,
          child: Scaffold(
            appBar: AppBar(
              title: Text(tr('${event['name']}')),
              actions: [
                if (event['public_url'] != null && event['public_enabled'] == true)
                  IconButton(tooltip: tr('Page publique de réservation'), onPressed: () => _publicLink(context, event), icon: const Icon(Icons.public)),
                if (_canManage(session)) IconButton(tooltip: tr('Réglages de l\'événement'), onPressed: () => editEvent(context, event), icon: const Icon(Icons.settings_outlined)),
              ],
              bottom: TabBar(isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [
                Tab(icon: Icon(Icons.grid_view_rounded, size: 18), text: tr('Plan')),
                Tab(icon: Icon(Icons.assignment_outlined, size: 18), text: tr('Réservations')),
                Tab(icon: Icon(Icons.workspace_premium_outlined, size: 18), text: tr('Sponsors & options')),
                Tab(icon: Icon(Icons.insights_outlined, size: 18), text: tr('Bilan')),
                if (ticketing) Tab(icon: Icon(Icons.confirmation_number_outlined, size: 18), text: tr('Billetterie')),
                if (ticketing) Tab(icon: Icon(Icons.how_to_reg_outlined, size: 18), text: tr('Inscriptions & avis')),
                if (ticketing) Tab(icon: Icon(Icons.groups_outlined, size: 18), text: tr('Fréquentation')),
              ]),
            ),
            body: ValueListenableBuilder(
              valueListenable: LocalDb.listen('ev_spaces'),
              builder: (context, _, _) => ValueListenableBuilder(
                valueListenable: LocalDb.listen('ev_bookings'),
                builder: (context, _, _) => TabBarView(children: [
                  _PlanTab(event: event),
                  _BookingsTab(event: event),
                  _PackagesTab(event: event),
                  _StatsTab(event: event),
                  if (ticketing) TicketingTab(event: event),
                  if (ticketing) RegistrationsTab(event: event),
                  if (ticketing) AttendanceView(eventId: '${event['id']}'),
                ]),
              ),
            ),
          ),
        );
      },
    );
  }
}

Future<void> _publicLink(BuildContext context, Map<String, dynamic> event) async {
  final url = '${event['public_url']}';
  await showDialog(
    context: context,
    builder: (c) => YAlertDialog(
      title: Text(tr('Page publique de réservation')),
      content: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
        SelectableText(tr(url), style: const TextStyle(fontWeight: FontWeight.w600)),
        const SizedBox(height: 10),
        Text(tr('Les exposants voient le plan et les stands libres, choisissent leurs emplacements et options : une option est posée à leur nom et vous êtes prévenu.'),
            style: TextStyle(color: Theme.of(c).hintColor)),
      ]),
      actions: [
        TextButton(onPressed: () => safeLaunchUrl(Uri.parse('https://wa.me/?text=${Uri.encodeComponent('Réservez votre stand pour ${event['name']} : $url')}'), mode: LaunchMode.externalApplication), child: Text(tr('WhatsApp'))),
        TextButton(onPressed: () => safeLaunchUrl(Uri.parse(url), mode: LaunchMode.externalApplication), child: Text(tr('Ouvrir'))),
        TextButton(onPressed: () => Clipboard.setData(ClipboardData(text: url)), child: Text(tr('Copier'))),
        FilledButton(onPressed: () => Navigator.pop(c), child: Text(tr('Fermer'))),
      ],
    ),
  );
}

// ---------------------------------------------------------------- Plan

class _PlanTab extends StatelessWidget {
  const _PlanTab({required this.event});

  final Map<String, dynamic> event;

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final st = evStats(event);
    final occ = evOccupancy('${event['id']}');
    final states = [for (final s in evSpaces('${event['id']}')) evPlanState(s, occ)];
    int n(String k) => states.where((x) => x == k).length;
    final cats = [for (final c in (event['categories'] as List? ?? const [])) asMap(c)];
    final narrow = MediaQuery.of(context).size.width < 700;
    return ListView(padding: EdgeInsets.all(narrow ? 12 : 20), children: [
      Wrap(spacing: 10, runSpacing: 10, crossAxisAlignment: WrapCrossAlignment.center, children: [
        _Legend(color: Colors.white, border: YColors.muted, label: tr('Libre (${st.free})')),
        _Legend(color: YColors.warning, label: tr('Option (${st.option})')),
        _Legend(color: YColors.blue, label: tr('Réservé, à encaisser (${n('confirmed')})')),
        _Legend(color: const Color(0xFF0FA3B1), label: tr('Acompte payé (${n('partial')})')),
        _Legend(color: YColors.success, label: tr('Payé (${n('paid')})')),
        _Legend(color: const Color(0xFFC3CAD6), label: tr('Indisponible (${st.blocked})')),
        for (final c in cats) _Legend(color: Colors.white, border: evColor(c['color']), label: '${c['name']}${c['price'] != null ? ' · ${money(toDouble(c['price']), '${event['currency'] ?? session.currency}')}' : ''}'),
        if (_canManage(session)) ...[
          const SizedBox(width: 8),
          OutlinedButton.icon(onPressed: () => editSpace(context, event), icon: const Icon(Icons.add_box_outlined, size: 18), label: Text(tr('Emplacement'))),
          OutlinedButton.icon(onPressed: () => generateRow(context, event), icon: const Icon(Icons.view_week_outlined, size: 18), label: Text(tr('Rangée'))),
        ],
      ]),
      const SizedBox(height: 12),
      EvPlan(event: event, onTap: (s) => _spaceSheet(context, event, s)),
      const SizedBox(height: 8),
      Text(tr('Touchez un emplacement pour le réserver ou voir l\'exposant.'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12.5)),
    ]);
  }
}

class _Legend extends StatelessWidget {
  const _Legend({required this.color, required this.label, this.border});

  final Color color;
  final Color? border;
  final String label;

  @override
  Widget build(BuildContext context) => Row(mainAxisSize: MainAxisSize.min, children: [
        Container(width: 14, height: 14, decoration: BoxDecoration(color: color, borderRadius: BorderRadius.circular(3), border: Border.all(color: border ?? color, width: 2))),
        const SizedBox(width: 6),
        Text(tr(label), style: const TextStyle(fontSize: 13)),
      ]);
}

/// Plan des emplacements (grille), coloré selon l'état des réservations.
class EvPlan extends StatelessWidget {
  const EvPlan({super.key, required this.event, this.onTap, this.selected = const {}});

  final Map<String, dynamic> event;
  final void Function(Map<String, dynamic> space)? onTap;
  final Set<String> selected;

  @override
  Widget build(BuildContext context) {
    final cols = toDouble(event['plan_cols']).toInt().clamp(4, 80);
    final rows = toDouble(event['plan_rows']).toInt().clamp(4, 80);
    final spaces = evSpaces('${event['id']}');
    final occ = evOccupancy('${event['id']}');
    final catColor = {for (final c in (event['categories'] as List? ?? const [])) '${(c as Map)['name']}': evColor(c['color'])};
    return LayoutBuilder(builder: (context, box) {
      final cell = (box.maxWidth / cols).clamp(22.0, 56.0);
      final w = cell * cols, h = cell * rows;
      final plan = Container(
        width: w,
        height: h,
        decoration: BoxDecoration(color: const Color(0xFFF7F9FC), borderRadius: BorderRadius.circular(12), border: Border.all(color: YColors.line)),
        child: Stack(children: [
          Positioned.fill(child: CustomPaint(painter: _GridPainter(cell))),
          for (final s in spaces) _box(context, s, occ, catColor, cell),
        ]),
      );
      return w > box.maxWidth ? SingleChildScrollView(scrollDirection: Axis.horizontal, child: plan) : plan;
    });
  }

  Widget _box(BuildContext context, Map<String, dynamic> s, Map<String, Map<String, dynamic>> occ, Map<String, Color> catColor, double cell) {
    final st = evPlanState(s, occ);
    final b = occ['${s['id']}'];
    final cat = catColor['${s['category']}'] ?? YColors.blue;
    final sel = selected.contains('${s['id']}');
    final (Color bg, Color fg, Color border) = switch (st) {
      'paid' => (YColors.success, Colors.white, YColors.success),
      'partial' => (const Color(0xFF0FA3B1), Colors.white, const Color(0xFF0FA3B1)),
      'confirmed' => (YColors.blue, Colors.white, YColors.blue),
      'option' => (YColors.warning, Colors.white, YColors.warning),
      'blocked' => (const Color(0xFFC3CAD6), YColors.night, const Color(0xFFC3CAD6)),
      _ => (Colors.white, cat, cat),
    };
    final bw = toDouble(s['w']) * cell, bh = toDouble(s['h']) * cell;
    return Positioned(
      left: toDouble(s['x']) * cell + 1.5,
      top: toDouble(s['y']) * cell + 1.5,
      width: bw - 3,
      height: bh - 3,
      child: Tooltip(
        message: [
          '${s['code']}',
          if (s['category'] != null) '${s['category']}',
          if (s['width_m'] != null && s['depth_m'] != null) '${_n(s['width_m'])} × ${_n(s['depth_m'])} m',
          if (s['area'] != null) '${_n(s['area'])} m²',
          if (b != null) '${b['exhibitor_name'] ?? b['partner_name'] ?? ''} (${switch (st) { 'paid' => 'payé', 'partial' => 'acompte payé', 'confirmed' => 'à encaisser', _ => evBookingLabels[b['status']] }})',
        ].join(' · '),
        child: Material(
          color: sel ? YColors.night : bg,
          borderRadius: BorderRadius.circular(6),
          child: InkWell(
            borderRadius: BorderRadius.circular(6),
            onTap: onTap == null ? null : () => onTap!(s),
            child: Container(
              decoration: BoxDecoration(borderRadius: BorderRadius.circular(6), border: Border.all(color: sel ? YColors.yellow : border, width: 2)),
              padding: const EdgeInsets.all(2),
              child: Center(
                child: Column(mainAxisSize: MainAxisSize.min, children: [
                  Text(tr('${s['code']}'), maxLines: 1, overflow: TextOverflow.clip, style: TextStyle(color: sel ? Colors.white : fg, fontWeight: FontWeight.w800, fontSize: (cell * .32).clamp(9, 14))),
                  if (b != null && bh > 46 && bw > 50)
                    Text(tr('${b['exhibitor_name'] ?? b['partner_name'] ?? ''}'), maxLines: 2, textAlign: TextAlign.center, overflow: TextOverflow.ellipsis,
                        style: TextStyle(color: sel ? Colors.white : fg, fontSize: (cell * .24).clamp(8, 11), height: 1.1)),
                ]),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _GridPainter extends CustomPainter {
  _GridPainter(this.cell);
  final double cell;

  @override
  void paint(Canvas canvas, Size size) {
    final p = Paint()
      ..color = const Color(0xFFE9EDF4)
      ..strokeWidth = 1;
    for (var x = cell; x < size.width; x += cell) {
      canvas.drawLine(Offset(x, 0), Offset(x, size.height), p);
    }
    for (var y = cell; y < size.height; y += cell) {
      canvas.drawLine(Offset(0, y), Offset(size.width, y), p);
    }
  }

  @override
  bool shouldRepaint(_GridPainter old) => old.cell != cell;
}

Future<void> _spaceSheet(BuildContext context, Map<String, dynamic> event, Map<String, dynamic> s) async {
  final session = context.read<Session>();
  final occ = evOccupancy('${event['id']}');
  final b = occ['${s['id']}'];
  final st = evSpaceStatus(s, occ);
  final cur = '${event['currency'] ?? session.currency}';
  await showAdaptiveSheet(
    context: context,
    showDragHandle: true,
    builder: (c) => SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(20, 0, 20, 20),
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Text(tr('Emplacement ${s['code']}'), style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
            const SizedBox(width: 10),
            StatusChip(switch (st) { 'free' => 'Libre', 'blocked' => 'Indisponible', _ => evBookingLabels[st] ?? st }, color: st == 'free' ? YColors.blue : (st == 'blocked' ? YColors.muted : _statusColor(st))),
          ]),
          const SizedBox(height: 6),
          Text(tr([
            if (s['category'] != null) '${s['category']}',
            if (s['width_m'] != null && s['depth_m'] != null) '${_n(s['width_m'])} m × ${_n(s['depth_m'])} m',
            if (s['area'] != null) '${_n(s['area'])} m²',
            if (s['zone'] != null) '${s['zone']}',
            '${money(toDouble(s['price']), cur)} HT',
            if (s['features'] != null) '${s['features']}',
          ].join(' · '))),
          if (b != null) ...[
            const Divider(height: 24),
            Text(tr('${b['exhibitor_name'] ?? b['partner_name']}'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
            Text(tr('${b['number'] ?? 'En attente de synchronisation'}${b['status'] == 'option' && b['option_until'] != null ? ' · option jusqu\'au ${dateFr(b['option_until'])}' : ''}${b['contact_phone'] != null ? ' · ${b['contact_phone']}' : ''}'),
                style: TextStyle(color: Theme.of(c).hintColor)),
          ],
          const SizedBox(height: 16),
          Wrap(spacing: 8, runSpacing: 8, children: [
            if (b != null && b['status'] == 'confirmed' && b['payment_status'] != 'paid' && b['number'] != null && _canSell(session))
              FilledButton.tonalIcon(
                onPressed: () {
                  Navigator.pop(c);
                  markBookingPaid(context, b);
                },
                icon: const Icon(Icons.payments_outlined),
                label: Text(tr('Marquer payé')),
              ),
            if (b != null)
              FilledButton.icon(
                onPressed: () {
                  Navigator.pop(c);
                  editBooking(context, event, b);
                },
                icon: const Icon(Icons.open_in_new),
                label: Text(tr('Ouvrir la réservation')),
              )
            else if (st == 'free' && _canSell(session) && event['status'] != 'closed' && event['status'] != 'done' && event['status'] != 'cancelled')
              FilledButton.icon(
                onPressed: () {
                  Navigator.pop(c);
                  editBooking(context, event, null, preselect: '${s['id']}');
                },
                icon: const Icon(Icons.add_shopping_cart),
                label: Text(tr('Réserver cet emplacement')),
              ),
            if (_canManage(session))
              OutlinedButton.icon(
                onPressed: () {
                  Navigator.pop(c);
                  editSpace(context, event, s);
                },
                icon: const Icon(Icons.edit_outlined),
                label: Text(tr('Modifier')),
              ),
          ]),
        ]),
      ),
    ),
  );
}

// ---------------------------------------------------------------- Réservations

class _BookingsTab extends StatefulWidget {
  const _BookingsTab({required this.event});

  final Map<String, dynamic> event;

  @override
  State<_BookingsTab> createState() => _BookingsTabState();
}

class _BookingsTabState extends State<_BookingsTab> {
  // '' = réservations en cours (option + confirmée), 'all' = toutes
  String _status = '';
  String _q = '';
  int _rk = 0;
  Map<String, String?> _f = {};
  FilterPeriod? _period;

  bool _st(Map<String, dynamic> b, String s) => s == 'all' || (s.isEmpty ? evActive.contains(b['status']) : b['status'] == s);

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final cur = '${widget.event['currency'] ?? session.currency}';
    final all = evBookings('${widget.event['id']}')..sort((a, b) => '${b['created_at'] ?? ''}${b['number'] ?? ''}'.compareTo('${a['created_at'] ?? ''}${a['number'] ?? ''}'));
    final base = all
        .where((b) => _q.isEmpty || '${b['number']} ${b['exhibitor_name']} ${b['partner_name']} ${b['contact_name'] ?? ''}'.toLowerCase().contains(_q))
        .where((b) => _f['pay'] == null || '${b['payment_status'] ?? 'none'}' == _f['pay'])
        .where((b) => _f['checkin'] == null || (b['checked_in_at'] != null) == (_f['checkin'] == 'yes'))
        .where((b) => _f['partner'] == null || '${b['partner_id'] ?? ''}' == _f['partner'])
        .where((b) => _period == null || _period!.contains(b['created_at']))
        .toList();
    final rows = base.where((b) => _st(b, _status)).toList();
    const stLabels = {'': 'En cours', 'option': 'Options', 'confirmed': 'Confirmées', 'expired': 'Expirées', 'cancelled': 'Annulées', 'all': 'Toutes'};
    final narrow = MediaQuery.of(context).size.width < 700;
    String codes(Map<String, dynamic> b) => [for (final id in (b['space_ids'] as List? ?? const [])) LocalDb.get('ev_spaces', '$id')?['code'] ?? '?'].join(', ');
    return Column(children: [
      Padding(
        padding: const EdgeInsets.only(top: 12),
        child: FilterBar(
          key: ValueKey(_rk),
          padding: EdgeInsets.fromLTRB(narrow ? 12 : 20, 0, narrow ? 12 : 20, 8),
          onSearch: (v) => setState(() => _q = v.toLowerCase()),
          search: _q,
          searchHint: 'Exposant, n° de réservation…',
          statuses: stLabels,
          status: _status,
          onStatus: (v) => setState(() => _status = v),
          statusCounts: {for (final k in stLabels.keys) k: base.where((b) => _st(b, k)).length},
          filters: [
            const FilterSpec('pay', 'Paiement', {'paid': 'Payée', 'partial': 'Acompte payé', 'unpaid': 'À encaisser', 'none': 'Sans facture'}, icon: Icons.payments_outlined),
            const FilterSpec('checkin', 'Accueil', {'yes': 'Accueilli', 'no': 'Non accueilli'}, icon: Icons.how_to_reg_outlined),
            FilterSpec('partner', 'Client', filterOptions(all, 'partner_id', label: (b) => '${b['partner_name'] ?? b['exhibitor_name'] ?? ''}'), icon: Icons.person_outline),
          ],
          values: _f,
          onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
          showPeriod: true,
          period: _period,
          onPeriod: (p) => setState(() => _period = p),
          count: rows.length,
          countLabel: 'réservation',
          onReset: () => setState(() => (_rk++, _q = '', _status = '', _f = {}, _period = null)),
          trailing: [
            if (_canSell(session)) FilledButton.icon(onPressed: () => editBooking(context, widget.event, null), icon: const Icon(Icons.add), label: Text(tr('Réservation'))),
          ],
        ),
      ),
      Expanded(
        child: rows.isEmpty
            ? EmptyState(icon: Icons.assignment_outlined, title: tr('Aucune réservation'))
            : ResponsiveList<Map<String, dynamic>>(
                items: rows,
                columns: const ['Réservation', 'Exposant', 'Emplacements', 'Total TTC', 'Paiement', 'Statut'],
                onTap: (b) => editBooking(context, widget.event, b),
                cells: (b) => [
                  Text(tr('${b['number'] ?? '…'}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                  Row(children: [InitialsAvatar('${b['exhibitor_name'] ?? b['partner_name'] ?? ''}'), const SizedBox(width: 10), Flexible(child: Text(tr('${b['exhibitor_name'] ?? b['partner_name'] ?? ''}'), overflow: TextOverflow.ellipsis))]),
                  Text(tr(codes(b).isEmpty ? '—' : codes(b)), overflow: TextOverflow.ellipsis),
                  Text(tr(money(evAmounts(b, widget.event).total, cur))),
                  _payChip(b),
                  _statusChipFor(b),
                ],
                mobileTile: (b) => Row(children: [
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(tr('${b['exhibitor_name'] ?? b['partner_name']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                      Text(tr('${b['number'] ?? '…'} · ${codes(b)} · ${money(evAmounts(b, widget.event).total, cur)}'), style: TextStyle(color: Theme.of(context).hintColor)),
                    ]),
                  ),
                  _statusChipFor(b),
                ]),
              ),
      ),
    ]);
  }
}

Widget _statusChipFor(Map<String, dynamic> b) {
  final s = '${b['status']}';
  final checked = b['checked_in_at'] != null;
  return StatusChip(checked ? 'Accueilli' : (evBookingLabels[s] ?? s), color: checked ? YColors.night : _statusColor(s), icon: checked ? Icons.how_to_reg : null);
}

Widget _payChip(Map<String, dynamic> b) => switch ('${b['payment_status'] ?? 'none'}') {
      'paid' => StatusChip(tr('Payée'), color: YColors.success),
      'partial' => StatusChip(tr('Acompte payé'), color: YColors.blue),
      'unpaid' => StatusChip(tr('À encaisser'), color: YColors.danger),
      _ => const Text('—'),
    };

// ---------------------------------------------------------------- Offres

class _PackagesTab extends StatefulWidget {
  const _PackagesTab({required this.event});

  final Map<String, dynamic> event;

  @override
  State<_PackagesTab> createState() => _PackagesTabState();
}

class _PackagesTabState extends State<_PackagesTab> {
  String _q = '';
  int _rk = 0;
  String _status = '';
  Map<String, String?> _f = {};

  Map<String, dynamic> get event => widget.event;

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final cur = '${event['currency'] ?? session.currency}';
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('ev_packages'),
      builder: (context, _, _) {
        final allPkgs = evPackages('${event['id']}');
        final q = _q.toLowerCase();
        final base = allPkgs
            .where((p) => q.isEmpty || '${p['name'] ?? ''} ${(p['benefits'] as List? ?? const []).join(' ')}'.toLowerCase().contains(q))
            .where((p) => _f['active'] == null || (p['active'] != false) == (_f['active'] == 'yes'))
            .where((p) => _f['online'] == null || (p['online'] != false) == (_f['online'] == 'yes'))
            .toList();
        bool kindOf(Map<String, dynamic> p, String k) => k.isEmpty || (k == 'sponsor' ? p['kind'] == 'sponsor' : p['kind'] != 'sponsor');
        final pkgs = base.where((p) => kindOf(p, _status)).toList();
        return ListView(padding: const EdgeInsets.all(16), children: [
          FilterBar(
            key: ValueKey(_rk),
            padding: const EdgeInsets.only(bottom: 12),
            onSearch: (v) => setState(() => _q = v),
            search: _q,
            searchHint: 'Nom de l\'offre, avantage…',
            statuses: const {'': 'Toutes', 'sponsor': 'Packs sponsors', 'option': 'Options'},
            status: _status,
            onStatus: (v) => setState(() => _status = v),
            statusCounts: {for (final k in ['', 'sponsor', 'option']) k: base.where((p) => kindOf(p, k)).length},
            filters: const [
              FilterSpec('active', 'État', {'yes': 'Active', 'no': 'Désactivée'}, icon: Icons.toggle_on_outlined),
              FilterSpec('online', 'En ligne', {'yes': 'Proposée en ligne', 'no': 'Non proposée en ligne'}, icon: Icons.public),
            ],
            values: _f,
            onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
            count: pkgs.length,
            countLabel: 'offre',
            onReset: () => setState(() => (_rk++, _q = '', _status = '', _f = {})),
          ),
          Row(children: [
            Expanded(child: Text(tr('Packs sponsors et options vendus avec ou sans stand (électricité, mobilier, badges, page du catalogue…).'), style: TextStyle(color: Theme.of(context).hintColor))),
            if (_canManage(session)) FilledButton.icon(onPressed: () => editPackage(context, event), icon: const Icon(Icons.add), label: Text(tr('Offre'))),
          ]),
          const SizedBox(height: 12),
          if (pkgs.isEmpty) EmptyState(icon: Icons.workspace_premium_outlined, title: tr(allPkgs.isEmpty ? 'Aucune offre' : 'Aucune offre ne correspond aux filtres')),
          for (final p in pkgs)
            Card(
              child: ListTile(
                onTap: _canManage(session) ? () => editPackage(context, event, p) : null,
                leading: CircleAvatar(
                  backgroundColor: (p['kind'] == 'sponsor' ? YColors.yellow : YColors.blue).withValues(alpha: .18),
                  child: Icon(p['kind'] == 'sponsor' ? Icons.workspace_premium : Icons.add_task, color: p['kind'] == 'sponsor' ? const Color(0xFF9A6B00) : YColors.blue),
                ),
                title: Text(tr('${p['name']}'), style: TextStyle(fontWeight: FontWeight.w700, color: p['active'] == false ? Colors.grey : null)),
                subtitle: Text(tr([
                  if ((p['benefits'] as List?)?.isNotEmpty == true) (p['benefits'] as List).join(' · '),
                  p['quantity'] == null ? '${evPackageSold(p)} vendu(s)' : '${evPackageSold(p)} / ${p['quantity']} vendu(s)',
                  if (p['online'] == false) 'non proposé en ligne',
                ].join('\n'))),
                isThreeLine: (p['benefits'] as List?)?.isNotEmpty == true,
                trailing: Text(tr(money(toDouble(p['price']), cur)), style: const TextStyle(fontWeight: FontWeight.w700)),
              ),
            ),
        ]);
      },
    );
  }
}

// ---------------------------------------------------------------- Bilan

class _StatsTab extends StatefulWidget {
  const _StatsTab({required this.event});

  final Map<String, dynamic> event;

  @override
  State<_StatsTab> createState() => _StatsTabState();
}

class _StatsTabState extends State<_StatsTab> {
  Map<String, dynamic>? _remote;

  @override
  void initState() {
    super.initState();
    context.read<Session>().api.get('evenements/events/${widget.event['id']}/stats').then((r) {
      if (mounted) setState(() => _remote = asMap(r['data']));
    }).catchError((_) {});
  }

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final cur = '${widget.event['currency'] ?? session.currency}';
    final st = evStats(widget.event);
    final r = _remote;
    Widget kpi(String label, String value, IconData icon, Color color) => KpiBox(width: 230, child: StatTile(label: label, value: value, icon: icon, color: color));
    return ListView(padding: const EdgeInsets.all(16), children: [
      KpiWrap(children: [
        kpi('Remplissage', '${st.occupancy.toStringAsFixed(1)} %', Icons.grid_view_rounded, YColors.blue),
        kpi('Surface réservée', '${_n(st.areaTaken.round())} / ${_n(st.area.round())} m²', Icons.square_foot, YColors.night),
        kpi('Confirmé (HT)', money(st.revenueConfirmed, cur), Icons.verified_outlined, YColors.success),
        kpi('En option (HT)', money(st.revenueOptions, cur), Icons.hourglass_top, YColors.warning),
        if (r != null) ...[
          kpi('Facturé (TTC)', money(toDouble(r['invoiced']), cur), Icons.receipt_long_outlined, YColors.blue),
          kpi('Encaissé', money(toDouble(r['paid']), cur), Icons.payments_outlined, YColors.success),
          kpi('Exposants accueillis', '${r['checked_in']} / ${r['exhibitors']}', Icons.how_to_reg, YColors.night),
        ],
      ]),
      if (r != null) ...[
        const SizedBox(height: 16),
        SectionCard(
          title: tr('Par catégorie d\'emplacement'),
          child: Column(children: [
            for (final c in (r['by_category'] as List? ?? const []))
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 6),
                child: Row(children: [
                  Expanded(flex: 3, child: Text(tr('${c['category']}'), style: const TextStyle(fontWeight: FontWeight.w600))),
                  Expanded(flex: 4, child: Text(tr('${c['confirmed']} confirmé(s) · ${c['option']} option(s) · ${c['free']} libre(s) / ${c['total']}'))),
                  Text(tr(money(toDouble(c['revenue']), cur))),
                ]),
              ),
          ]),
        ),
      ] else
        Padding(padding: const EdgeInsets.only(top: 12), child: Text(tr('Facturation et encaissements : disponibles en ligne.'), style: TextStyle(color: Theme.of(context).hintColor))),
    ]);
  }
}

// ======================================================================
// Formulaires
// ======================================================================

Future<void> editEvent(BuildContext context, [Map<String, dynamic>? event]) =>
    showFormDialog(context, title: event == null ? 'Nouvel événement' : 'Réglages de l\'événement', maxWidth: 720, builder: (_) => _EventForm(event: event));

class _EventForm extends StatefulWidget {
  const _EventForm({this.event});

  final Map<String, dynamic>? event;

  @override
  State<_EventForm> createState() => _EventFormState();
}

class _EventFormState extends State<_EventForm> {
  late final Map<String, dynamic> e = widget.event ?? {};
  late final _c = {
    for (final k in ['name', 'venue', 'city', 'description', 'terms']) k: TextEditingController(text: '${e[k] ?? ''}'),
    for (final k in ['plan_cols', 'plan_rows', 'tax_rate', 'deposit_percent', 'balance_days', 'option_days'])
      k: TextEditingController(text: _n(e[k] ?? {'plan_cols': 24, 'plan_rows': 14, 'tax_rate': 18, 'deposit_percent': 50, 'balance_days': 15, 'option_days': 7}[k])),
  };
  late String _status = '${e['status'] ?? 'draft'}';
  late String? _start = e['start_date'] as String?;
  late String? _end = e['end_date'] as String?;
  late bool _public = e['public_enabled'] == true;
  late bool _ticketing = e['ticketing_enabled'] == true;
  late bool _onlineDeposit = e['online_deposit'] == true;
  late final _capacity = TextEditingController(text: _n(e['capacity']));
  late final List<Map<String, dynamic>> _cats = [for (final c in (e['categories'] as List? ?? const [])) asMap(c)];

  Future<void> _date(bool start) async {
    final d = await pickDate(context, initialDate: DateTime.tryParse((start ? _start : _end) ?? _start ?? '') ?? DateTime.now().add(const Duration(days: 30)), firstDate: DateTime(2020), lastDate: DateTime(2100));
    if (d != null) setState(() => start ? (_start = isoDate(d), _end ??= isoDate(d)) : _end = isoDate(d));
  }

  Future<void> _save() async {
    if (_c['name']!.text.trim().isEmpty || _start == null || _end == null) return toast(context, tr('Nom et dates obligatoires.'), error: true);
    if (_end!.compareTo(_start!) < 0) return toast(context, tr('La date de fin précède le début.'), error: true);
    final saved = await context.read<SyncEngine>().save('ev_events', {
      if (widget.event != null) 'id': widget.event!['id'],
      for (final k in ['name', 'venue', 'city', 'description', 'terms']) k: _c[k]!.text.trim().isEmpty ? null : _c[k]!.text.trim(),
      for (final k in ['plan_cols', 'plan_rows', 'balance_days', 'option_days']) k: toDouble(_c[k]!.text).toInt(),
      for (final k in ['tax_rate', 'deposit_percent']) k: toDouble(_c[k]!.text),
      'status': _status,
      'start_date': _start,
      'end_date': _end,
      'public_enabled': _public,
      if (context.read<Session>().hasModule('billetterie')) ...{'ticketing_enabled': _ticketing, 'capacity': _capacity.text.trim().isEmpty ? null : toDouble(_capacity.text).toInt()},
      'online_deposit': _onlineDeposit,
      'categories': [for (final c in _cats) if ('${c['name'] ?? ''}'.trim().isNotEmpty) c],
    });
    if (!mounted) return;
    Navigator.pop(context);
    if (widget.event == null) Navigator.of(context).push(MaterialPageRoute(builder: (_) => EventDetailScreen(eventId: '${saved['id']}')));
  }

  @override
  Widget build(BuildContext context) {
    final numFmt = [FilteringTextInputFormatter.allow(RegExp(r'[0-9.,]'))];
    Widget num(String k, String label, {String? suffix}) => Expanded(child: Field(label: label, child: TextField(controller: _c[k], inputFormatters: numFmt, keyboardType: TextInputType.number, decoration: InputDecoration(suffixText: trn(suffix)))));
    return ListView(padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), shrinkWrap: true, children: [
      Field(label: tr('Nom'), child: TextField(controller: _c['name'], autofocus: widget.event == null, decoration: InputDecoration(hintText: tr('Salon de l\'élevage 2027, Foire de Bouaké…')))),
      Row(children: [
        Expanded(child: Field(label: tr('Lieu'), child: TextField(controller: _c['venue']))),
        const SizedBox(width: 12),
        Expanded(child: Field(label: tr('Ville'), child: TextField(controller: _c['city']))),
      ]),
      Row(children: [
        Expanded(child: Field(label: tr('Début'), child: OutlinedButton(onPressed: () => _date(true), child: Text(tr(_start == null ? 'Choisir' : dateFr(_start)))))),
        const SizedBox(width: 12),
        Expanded(child: Field(label: tr('Fin'), child: OutlinedButton(onPressed: () => _date(false), child: Text(tr(_end == null ? 'Choisir' : dateFr(_end)))))),
        const SizedBox(width: 12),
        Expanded(
          child: Field(
            label: tr('État'),
            child: DropdownButtonFormField<String>(
              initialValue: _status,
              isExpanded: true,
              items: [for (final s in evEventLabels.entries) DropdownMenuItem(value: s.key, child: Text(tr(s.value)))],
              onChanged: (v) => setState(() => _status = v!),
            ),
          ),
        ),
      ]),
      Row(children: [
        num('plan_cols', 'Plan : colonnes'),
        const SizedBox(width: 12),
        num('plan_rows', 'Plan : rangées'),
        const SizedBox(width: 12),
        num('tax_rate', 'TVA', suffix: '%'),
      ]),
      Row(children: [
        num('deposit_percent', 'Acompte à la confirmation', suffix: '%'),
        const SizedBox(width: 12),
        num('balance_days', 'Solde facturé (jours avant)'),
        const SizedBox(width: 12),
        num('option_days', 'Durée d\'une option (jours)'),
      ]),
      const SizedBox(height: 4),
      Text(tr('Catégories d\'emplacements'), style: Theme.of(context).textTheme.titleSmall),
      Text(tr('Prix et surface par défaut des stands, couleur sur le plan.'), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
      const SizedBox(height: 8),
      for (var i = 0; i < _cats.length; i++)
        Padding(
          padding: const EdgeInsets.only(bottom: 8),
          child: Row(children: [
            PopupMenuButton<String>(
              tooltip: tr('Couleur'),
              onSelected: (v) => setState(() => _cats[i]['color'] = v),
              itemBuilder: (_) => [for (final c in _palette) PopupMenuItem(value: c, child: Container(width: 60, height: 18, color: evColor(c)))],
              child: Container(width: 28, height: 28, decoration: BoxDecoration(color: evColor(_cats[i]['color']), borderRadius: BorderRadius.circular(6))),
            ),
            const SizedBox(width: 8),
            Expanded(flex: 3, child: TextFormField(initialValue: '${_cats[i]['name'] ?? ''}', decoration: InputDecoration(isDense: true, labelText: tr('Nom (ex. Standard 9 m²)')), onChanged: (v) => _cats[i]['name'] = v.trim())),
            const SizedBox(width: 8),
            Expanded(flex: 2, child: TextFormField(initialValue: _n(_cats[i]['price']), inputFormatters: numFmt, decoration: InputDecoration(isDense: true, labelText: tr('Prix HT')), onChanged: (v) => _cats[i]['price'] = toDouble(v))),
            const SizedBox(width: 8),
            SizedBox(width: 80, child: TextFormField(initialValue: _n(_cats[i]['area']), inputFormatters: numFmt, decoration: const InputDecoration(isDense: true, labelText: 'm²'), onChanged: (v) => _cats[i]['area'] = toDouble(v))),
            IconButton(onPressed: () => setState(() => _cats.removeAt(i)), icon: const Icon(Icons.close, size: 18)),
          ]),
        ),
      Align(
        alignment: Alignment.centerLeft,
        child: TextButton.icon(onPressed: () => setState(() => _cats.add({'name': '', 'color': _palette[_cats.length % _palette.length]})), icon: const Icon(Icons.add), label: Text(tr('Ajouter une catégorie'))),
      ),
      SwitchListTile(
        contentPadding: EdgeInsets.zero,
        title: Text(tr('Page publique de réservation')),
        subtitle: Text(tr('Les exposants choisissent leurs stands en ligne (ventes ouvertes) : une option est posée à leur nom.')),
        value: _public,
        onChanged: (v) => setState(() => _public = v),
      ),
      if (_public)
        SwitchListTile(
          contentPadding: EdgeInsets.zero,
          title: Text(tr('Acompte payable en ligne')),
          subtitle: Text(tr('La demande en ligne émet la facture d\'acompte ; payée par Mobile Money ou carte, la réservation est confirmée automatiquement.')),
          value: _onlineDeposit,
          onChanged: (v) => setState(() => _onlineDeposit = v),
        ),
      if (context.read<Session>().hasModule('billetterie')) ...[
        SwitchListTile(
          contentPadding: EdgeInsets.zero,
          title: Text(tr('Billetterie en ligne')),
          subtitle: Text(tr('Page d\'achat des billets visiteurs (paiement en ligne, billets avec QR code envoyés à l\'acheteur).')),
          value: _ticketing,
          onChanged: (v) => setState(() => _ticketing = v),
        ),
        Field(label: tr('Jauge (visiteurs, facultatif)'), child: TextField(controller: _capacity, keyboardType: TextInputType.number, decoration: InputDecoration(hintText: tr('Illimitée')))),
      ],
      Field(label: tr('Présentation (page publique)'), child: TextField(controller: _c['description'], maxLines: 3)),
      Field(label: tr('Conditions (factures et page publique)'), child: TextField(controller: _c['terms'], maxLines: 2, decoration: InputDecoration(hintText: tr('Annulation, montage, démontage…')))),
      const SizedBox(height: 8),
      Row(children: [
        if (widget.event != null)
          TextButton(
            style: TextButton.styleFrom(foregroundColor: YColors.danger),
            onPressed: () async {
              if (!await confirm(context, tr('Supprimer l\'événement ?'), tr('Possible seulement sans réservation. Le plan et les offres sont supprimés.'), ok: tr('Supprimer'), danger: true)) return;
              if (!context.mounted) return;
              await context.read<SyncEngine>().remove('ev_events', '${widget.event!['id']}');
              if (context.mounted) Navigator.of(context)..pop()..pop();
            },
            child: Text(tr('Supprimer')),
          ),
        const Spacer(),
        SaveButton(onPressed: _save, child: Text(tr('Enregistrer'))),
      ]),
    ]);
  }
}

Future<void> editSpace(BuildContext context, Map<String, dynamic> event, [Map<String, dynamic>? space]) =>
    showFormDialog(context, title: space == null ? 'Nouvel emplacement' : 'Emplacement ${space['code']}', builder: (_) => _SpaceForm(event: event, space: space));

class _SpaceForm extends StatefulWidget {
  const _SpaceForm({required this.event, this.space});

  final Map<String, dynamic> event;
  final Map<String, dynamic>? space;

  @override
  State<_SpaceForm> createState() => _SpaceFormState();
}

class _SpaceFormState extends State<_SpaceForm> {
  late final s = widget.space ?? {};
  late final (int, int) _free = evFreeCell(widget.event, 2, 2);
  late final _c = {
    for (final k in ['code', 'zone', 'features', 'notes']) k: TextEditingController(text: '${s[k] ?? ''}'),
    'area': TextEditingController(text: _n(s['area'])),
    'width_m': TextEditingController(text: _n(s['width_m'])),
    'depth_m': TextEditingController(text: _n(s['depth_m'])),
    'price': TextEditingController(text: _n(s['price'])),
    'x': TextEditingController(text: '${s['x'] ?? _free.$1}'),
    'y': TextEditingController(text: '${s['y'] ?? _free.$2}'),
    'w': TextEditingController(text: '${s['w'] ?? 2}'),
    'h': TextEditingController(text: '${s['h'] ?? 2}'),
  };
  late String? _cat = s['category'] as String?;
  late bool _blocked = s['blocked'] == true;
  List<Map<String, dynamic>> get _cats => [for (final c in (widget.event['categories'] as List? ?? const [])) asMap(c)];

  /// Surface calculée à partir des dimensions réelles du stand.
  void _dims() {
    final w = toDouble(_c['width_m']!.text.replaceAll(',', '.')), d = toDouble(_c['depth_m']!.text.replaceAll(',', '.'));
    if (w > 0 && d > 0) setState(() => _c['area']!.text = _n((w * d * 100).round() / 100));
  }

  Future<void> _save() async {
    final code = _c['code']!.text.trim();
    if (code.isEmpty) return toast(context, tr('Indiquez le code de l\'emplacement (A01…).'), error: true);
    final x = toDouble(_c['x']!.text).toInt(), y = toDouble(_c['y']!.text).toInt(), w = toDouble(_c['w']!.text).toInt().clamp(1, 80), h = toDouble(_c['h']!.text).toInt().clamp(1, 80);
    if (x + w > toDouble(widget.event['plan_cols']) || y + h > toDouble(widget.event['plan_rows'])) return toast(context, tr('L\'emplacement dépasse du plan.'), error: true);
    for (final o in evSpaces('${widget.event['id']}').where((o) => o['id'] != s['id'])) {
      final ox = toDouble(o['x']).toInt(), oy = toDouble(o['y']).toInt(), ow = toDouble(o['w']).toInt(), oh = toDouble(o['h']).toInt();
      if (x < ox + ow && ox < x + w && y < oy + oh && oy < y + h) return toast(context, tr('Chevauche ${o['code']} sur le plan.'), error: true);
      if ('${o['code']}'.toLowerCase() == code.toLowerCase()) return toast(context, tr('Code déjà utilisé.'), error: true);
    }
    await context.read<SyncEngine>().save('ev_spaces', {
      if (widget.space != null) 'id': widget.space!['id'],
      'event_id': widget.event['id'],
      'code': code,
      'category': _cat,
      for (final k in ['zone', 'features', 'notes']) k: _c[k]!.text.trim().isEmpty ? null : _c[k]!.text.trim(),
      'area': _c['area']!.text.trim().isEmpty ? null : toDouble(_c['area']!.text),
      'width_m': _c['width_m']!.text.trim().isEmpty ? null : toDouble(_c['width_m']!.text.replaceAll(',', '.')),
      'depth_m': _c['depth_m']!.text.trim().isEmpty ? null : toDouble(_c['depth_m']!.text.replaceAll(',', '.')),
      'price': toDouble(_c['price']!.text),
      'x': x, 'y': y, 'w': w, 'h': h,
      'blocked': _blocked,
    });
    if (mounted) Navigator.pop(context);
  }

  @override
  Widget build(BuildContext context) {
    final numFmt = [FilteringTextInputFormatter.allow(RegExp(r'[0-9.,]'))];
    Widget f(String k, String label) => Expanded(child: Field(label: label, child: TextField(controller: _c[k], inputFormatters: numFmt, keyboardType: TextInputType.number)));
    return ListView(padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), shrinkWrap: true, children: [
      Row(children: [
        Expanded(child: Field(label: tr('Code'), child: TextField(controller: _c['code'], autofocus: widget.space == null, textCapitalization: TextCapitalization.characters))),
        const SizedBox(width: 12),
        Expanded(
          flex: 2,
          child: Field(
            label: tr('Catégorie'),
            child: DropdownButtonFormField<String?>(
              initialValue: _cats.any((c) => c['name'] == _cat) ? _cat : null,
              isExpanded: true,
              items: [const DropdownMenuItem(value: null, child: Text('—')), for (final c in _cats) DropdownMenuItem(value: '${c['name']}', child: Text(tr('${c['name']}')))],
              onChanged: (v) => setState(() {
                _cat = v;
                final c = _cats.where((c) => c['name'] == v).firstOrNull;
                if (c != null && _c['price']!.text.isEmpty) _c['price']!.text = _n(c['price']);
                if (c != null && _c['area']!.text.isEmpty) _c['area']!.text = _n(c['area']);
              }),
            ),
          ),
        ),
      ]),
      Row(children: [f('price', 'Prix HT'), const SizedBox(width: 12), Expanded(child: Field(label: tr('Zone / hall'), child: TextField(controller: _c['zone'])))]),
      Row(children: [
        Expanded(child: Field(label: tr('Largeur (m)'), child: TextField(controller: _c['width_m'], inputFormatters: numFmt, keyboardType: TextInputType.number, onChanged: (_) => _dims()))),
        const SizedBox(width: 12),
        Expanded(child: Field(label: tr('Profondeur (m)'), child: TextField(controller: _c['depth_m'], inputFormatters: numFmt, keyboardType: TextInputType.number, onChanged: (_) => _dims()))),
        const SizedBox(width: 12),
        f('area', 'Surface (m²)'),
      ]),
      Text(tr('Position sur le plan (${_n(widget.event['plan_cols'])} × ${_n(widget.event['plan_rows'])} cases)'), style: Theme.of(context).textTheme.titleSmall),
      const SizedBox(height: 6),
      Row(children: [f('x', 'Colonne'), const SizedBox(width: 8), f('y', 'Rangée'), const SizedBox(width: 8), f('w', 'Largeur'), const SizedBox(width: 8), f('h', 'Profondeur')]),
      Field(label: tr('Équipements'), child: TextField(controller: _c['features'], decoration: InputDecoration(hintText: tr('Angle, électricité, point d\'eau…')))),
      SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Indisponible à la vente')), subtitle: Text(tr('Scène, allée, organisateur…')), value: _blocked, onChanged: (v) => setState(() => _blocked = v)),
      Row(children: [
        if (widget.space != null)
          TextButton(
            style: TextButton.styleFrom(foregroundColor: YColors.danger),
            onPressed: () async {
              await context.read<SyncEngine>().remove('ev_spaces', '${widget.space!['id']}');
              if (context.mounted) Navigator.pop(context);
            },
            child: Text(tr('Supprimer')),
          ),
        const Spacer(),
        SaveButton(onPressed: _save, child: Text(tr('Enregistrer'))),
      ]),
    ]);
  }
}

/// Génère une rangée d'emplacements A01…A10 (en ligne).
Future<void> generateRow(BuildContext context, Map<String, dynamic> event) async {
  final cats = [for (final c in (event['categories'] as List? ?? const [])) asMap(c)];
  final free = evFreeCell(event, 2, 2);
  final c = {
    'prefix': TextEditingController(text: 'A'),
    'start': TextEditingController(text: '1'),
    'count': TextEditingController(text: '8'),
    'x': TextEditingController(text: '${free.$1}'),
    'y': TextEditingController(text: '${free.$2}'),
    'w': TextEditingController(text: '2'),
    'h': TextEditingController(text: '2'),
    'gap': TextEditingController(text: '0'),
  };
  String? cat = cats.firstOrNull?['name'] as String?;
  var vertical = false;
  final api = context.read<Session>().api;
  final sync = context.read<SyncEngine>();
  await showDialog(
    context: context,
    builder: (dc) => StatefulBuilder(
      builder: (dc, set) {
        Widget f(String k, String label) => Expanded(child: TextField(controller: c[k], keyboardType: TextInputType.number, decoration: InputDecoration(isDense: true, labelText: trn(label))));
        return YAlertDialog(
          title: Text(tr('Générer une rangée d\'emplacements')),
          content: SizedBox(
            width: 460,
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              Row(children: [f('prefix', 'Préfixe'), const SizedBox(width: 8), f('start', 'Premier n°'), const SizedBox(width: 8), f('count', 'Nombre')]),
              const SizedBox(height: 10),
              Row(children: [f('x', 'Colonne de départ'), const SizedBox(width: 8), f('y', 'Rangée de départ')]),
              const SizedBox(height: 10),
              Row(children: [f('w', 'Largeur'), const SizedBox(width: 8), f('h', 'Profondeur'), const SizedBox(width: 8), f('gap', 'Écart')]),
              const SizedBox(height: 10),
              if (cats.isNotEmpty)
                DropdownButtonFormField<String?>(
                  initialValue: cat,
                  decoration: InputDecoration(isDense: true, labelText: tr('Catégorie (prix et surface)')),
                  items: [for (final x in cats) DropdownMenuItem(value: '${x['name']}', child: Text(tr('${x['name']}')))],
                  onChanged: (v) => set(() => cat = v),
                ),
              SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Rangée verticale')), value: vertical, onChanged: (v) => set(() => vertical = v)),
            ]),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(dc), child: Text(tr('Annuler'))),
            SaveButton(
              onPressed: () async {
                try {
                  await sync.sync();
                  await api.post('evenements/events/${event['id']}/spaces/generate', {
                    'prefix': c['prefix']!.text.trim(),
                    for (final k in ['start', 'count', 'x', 'y', 'w', 'h', 'gap']) k: toDouble(c[k]!.text).toInt(),
                    'direction': vertical ? 'vertical' : 'horizontal',
                    'category': ?cat,
                  });
                  await sync.sync();
                  if (dc.mounted) Navigator.pop(dc);
                } catch (e) {
                  if (dc.mounted) toast(dc, errorText(e), error: true);
                }
              },
              child: Text(tr('Générer')),
            ),
          ],
        );
      },
    ),
  );
}

Future<void> editPackage(BuildContext context, Map<String, dynamic> event, [Map<String, dynamic>? pkg]) =>
    showFormDialog(context, title: pkg == null ? 'Nouvelle offre' : '${pkg['name']}', builder: (_) => _PackageForm(event: event, pkg: pkg));

class _PackageForm extends StatefulWidget {
  const _PackageForm({required this.event, this.pkg});

  final Map<String, dynamic> event;
  final Map<String, dynamic>? pkg;

  @override
  State<_PackageForm> createState() => _PackageFormState();
}

class _PackageFormState extends State<_PackageForm> {
  late final p = widget.pkg ?? {};
  late final _name = TextEditingController(text: '${p['name'] ?? ''}');
  late final _desc = TextEditingController(text: '${p['description'] ?? ''}');
  late final _price = TextEditingController(text: _n(p['price']));
  late final _qty = TextEditingController(text: _n(p['quantity']));
  late final _benefits = TextEditingController(text: ((p['benefits'] as List?) ?? const []).join('\n'));
  late String _kind = '${p['kind'] ?? 'option'}';
  late bool _active = p['active'] != false;
  late bool _online = p['online'] != false;

  Future<void> _save() async {
    if (_name.text.trim().isEmpty) return toast(context, tr('Nommez l\'offre.'), error: true);
    await context.read<SyncEngine>().save('ev_packages', {
      if (widget.pkg != null) 'id': widget.pkg!['id'],
      'event_id': widget.event['id'],
      'kind': _kind,
      'name': _name.text.trim(),
      'description': _desc.text.trim().isEmpty ? null : _desc.text.trim(),
      'price': toDouble(_price.text),
      'quantity': _qty.text.trim().isEmpty ? null : toDouble(_qty.text).toInt(),
      'benefits': _benefits.text.split('\n').map((e) => e.trim()).where((e) => e.isNotEmpty).toList(),
      'active': _active,
      'online': _online,
    });
    if (mounted) Navigator.pop(context);
  }

  @override
  Widget build(BuildContext context) => ListView(padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), shrinkWrap: true, children: [
        Field(
          label: tr('Type'),
          child: SegmentedButton<String>(
            segments: [ButtonSegment(value: 'sponsor', label: Text(tr('Pack sponsor')), icon: Icon(Icons.workspace_premium)), ButtonSegment(value: 'option', label: Text(tr('Option / service')), icon: Icon(Icons.add_task))],
            selected: {_kind},
            onSelectionChanged: (s) => setState(() => _kind = s.first),
          ),
        ),
        Field(label: tr('Nom'), child: TextField(controller: _name, decoration: InputDecoration(hintText: trn(_kind == 'sponsor' ? 'Sponsor Or, Partenaire officiel…' : 'Électricité 3 kW, Table et 2 chaises, Badge supplémentaire…')))),
        Row(children: [
          Expanded(child: Field(label: tr('Prix HT'), child: TextField(controller: _price, keyboardType: TextInputType.number))),
          const SizedBox(width: 12),
          Expanded(child: Field(label: tr('Quantité disponible'), child: TextField(controller: _qty, keyboardType: TextInputType.number, decoration: InputDecoration(hintText: tr('Illimitée'))))),
        ]),
        Field(label: tr('Contreparties (une par ligne)'), child: TextField(controller: _benefits, maxLines: 4, decoration: InputDecoration(hintText: tr('Logo sur l\'affiche\n4 badges exposant\nStand de 18 m² en entrée')))),
        Field(label: tr('Description'), child: TextField(controller: _desc, maxLines: 2)),
        SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Proposée sur la page publique')), value: _online, onChanged: (v) => setState(() => _online = v)),
        SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Active')), value: _active, onChanged: (v) => setState(() => _active = v)),
        Row(children: [
          if (widget.pkg != null)
            TextButton(
              style: TextButton.styleFrom(foregroundColor: YColors.danger),
              onPressed: () async {
                await context.read<SyncEngine>().remove('ev_packages', '${widget.pkg!['id']}');
                if (context.mounted) Navigator.pop(context);
              },
              child: Text(tr('Supprimer')),
            ),
          const Spacer(),
          SaveButton(onPressed: _save, child: Text(tr('Enregistrer'))),
        ]),
      ]);
}

// ---------------------------------------------------------------- Réservation

Future<void> editBooking(BuildContext context, Map<String, dynamic> event, Map<String, dynamic>? booking, {String? preselect}) =>
    showFormDialog(context, title: booking == null ? 'Nouvelle réservation' : 'Réservation ${booking['number'] ?? ''}', maxWidth: 780, builder: (_) => _BookingForm(event: event, booking: booking, preselect: preselect));

class _BookingForm extends StatefulWidget {
  const _BookingForm({required this.event, this.booking, this.preselect});

  final Map<String, dynamic> event;
  final Map<String, dynamic>? booking;
  final String? preselect;

  @override
  State<_BookingForm> createState() => _BookingFormState();
}

class _BookingFormState extends State<_BookingForm> {
  late final b = widget.booking ?? {};
  late String? _partner = b['partner_id'] as String?;
  late final Set<String> _spaces = {for (final s in (b['space_ids'] as List? ?? const [])) '$s', ?widget.preselect};
  late final Map<String, int> _items = {for (final it in (b['items'] as List? ?? const [])) '${(it as Map)['package_id']}': toDouble(it['quantity']).toInt()};
  late final Map<String, double> _prices = {for (final it in (b['items'] as List? ?? const [])) '${(it as Map)['package_id']}': toDouble(it['unit_price'])};
  late final _c = {
    for (final k in ['exhibitor_name', 'contact_name', 'contact_phone', 'contact_email', 'notes']) k: TextEditingController(text: '${b[k] ?? ''}'),
    'discount_percent': TextEditingController(text: _n(b['discount_percent'] ?? 0)),
  };
  late String? _until = b['option_until'] as String?;
  bool _busy = false;

  String get _status => '${b['status'] ?? 'option'}';
  bool get _locked => b['balance_invoice_id'] != null && evActive.contains(_status);
  bool get _editable => (_status == 'option' || _status == 'confirmed' || _status == 'expired' || widget.booking == null) && _canSell(context.read<Session>());

  Map<String, dynamic> _payload({String? status}) => {
        if (widget.booking != null) 'id': widget.booking!['id'],
        'event_id': widget.event['id'],
        'partner_id': _partner,
        for (final k in ['exhibitor_name', 'contact_name', 'contact_phone', 'contact_email', 'notes']) k: _c[k]!.text.trim().isEmpty ? null : _c[k]!.text.trim(),
        if (!_locked) ...{
          'space_ids': _spaces.toList(),
          'items': [for (final e in _items.entries) if (e.value > 0) {'package_id': e.key, 'quantity': e.value, if (_prices[e.key] != null) 'unit_price': _prices[e.key]}],
          'discount_percent': toDouble(_c['discount_percent']!.text),
        },
        if (_status == 'option' || status == 'option') 'option_until': _until,
        'status': ?status,
      };

  Future<void> _save({String? status}) async {
    if (_partner == null) return toast(context, tr('Choisissez l\'exposant (client).'), error: true);
    if (_spaces.isEmpty && !_items.values.any((q) => q > 0)) return toast(context, tr('Choisissez au moins un emplacement ou une offre.'), error: true);
    final sync = context.read<SyncEngine>();
    if (status == 'cancelled' && !await confirm(context, tr('Annuler la réservation ?'), tr('Les emplacements sont libérés et les factures non payées annulées.'), ok: tr('Annuler la réservation'), danger: true)) return;
    if (!mounted) return;
    if (status == 'confirmed' && !await confirm(context, tr('Confirmer la réservation ?'), toDouble(widget.event['deposit_percent']) > 0 && toDouble(widget.event['deposit_percent']) < 100 ? 'Une facture d\'acompte de ${_n(widget.event['deposit_percent'])} % est émise à la synchronisation.' : 'La facture est émise à la synchronisation.', ok: tr('Confirmer'))) return;
    if (!mounted) return;
    await sync.save('ev_bookings', _payload(status: status));
    if (mounted) {
      Navigator.pop(context);
      toast(context, status == 'confirmed' ? 'Réservation confirmée.' : (status == 'cancelled' ? 'Réservation annulée.' : 'Réservation enregistrée.'));
    }
  }

  Future<void> _api(String path, String done) async {
    setState(() => _busy = true);
    final sync = context.read<SyncEngine>();
    final api = context.read<Session>().api;
    try {
      await sync.sync();
      final r = await api.post(path);
      if (r is Map && r['booking'] is Map) await LocalDb.put('ev_bookings', asMap(r['booking']));
      if (r is Map && r['data'] is Map && asMap(r['data'])['event_id'] != null) await LocalDb.put('ev_bookings', asMap(r['data']));
      await sync.sync();
      if (mounted) {
        Navigator.pop(context);
        toast(context, done);
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final session = context.read<Session>();
    final cur = '${widget.event['currency'] ?? session.currency}';
    final occ = evOccupancy('${widget.event['id']}');
    final spaces = evSpaces('${widget.event['id']}');
    final editable0 = _editable;
    final selectable = spaces.where((s) => _spaces.contains('${s['id']}') || (!_locked && editable0 && evSpaceStatus(s, occ) == 'free')).toList();
    final pkgs = evPackages('${widget.event['id']}').where((p) => p['active'] != false || _items.containsKey('${p['id']}')).toList();
    final customers = LocalDb.all('partners').where((p) => p['type'] != 'supplier' && p['active'] != false).toList()..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));
    final preview = evAmounts({
      'space_ids': _spaces.toList(),
      'items': [for (final e in _items.entries) {'package_id': e.key, 'quantity': e.value, 'unit_price': _prices[e.key]}],
      'discount_percent': toDouble(_c['discount_percent']!.text),
    }, widget.event);
    final editable = _editable;
    final hint = Theme.of(context).hintColor;
    final invoices = [for (final i in (b['invoices'] as List? ?? const [])) asMap(i)];

    return ListView(padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), shrinkWrap: true, children: [
      if (widget.booking != null)
        Padding(
          padding: const EdgeInsets.only(bottom: 10),
          child: Wrap(spacing: 8, runSpacing: 6, crossAxisAlignment: WrapCrossAlignment.center, children: [
            _statusChipFor(b),
            if (b['payment_status'] != null && b['payment_status'] != 'none') _payChip(b),
            if (b['source'] == 'online') StatusChip(tr('Demande en ligne'), color: YColors.blue, icon: Icons.public),
            if (_status == 'option' && b['option_until'] != null) Text(tr('Option jusqu\'au ${dateFr(b['option_until'])}'), style: TextStyle(color: hint)),
            if (b['last_error'] != null) Text(tr('${b['last_error']}'), style: const TextStyle(color: YColors.danger)),
          ]),
        ),
      Row(children: [
        Expanded(
          flex: 3,
          child: Field(
            label: tr('Exposant (client)'),
            child: DropdownButtonFormField<String?>(
              initialValue: customers.any((c) => c['id'] == _partner) ? _partner : null,
              isExpanded: true,
              items: [for (final c in customers) DropdownMenuItem(value: '${c['id']}', child: Text(tr('${c['name']}'), overflow: TextOverflow.ellipsis))],
              onChanged: !editable || b['deposit_invoice_id'] != null || b['balance_invoice_id'] != null
                  ? null
                  : (v) => setState(() {
                        _partner = v;
                        final p = LocalDb.get('partners', v);
                        if (_c['exhibitor_name']!.text.isEmpty) _c['exhibitor_name']!.text = '${p?['name'] ?? ''}';
                        if (_c['contact_phone']!.text.isEmpty) _c['contact_phone']!.text = '${p?['phone'] ?? ''}';
                        if (_c['contact_email']!.text.isEmpty) _c['contact_email']!.text = '${p?['email'] ?? ''}';
                      }),
            ),
          ),
        ),
        const SizedBox(width: 12),
        Expanded(flex: 2, child: Field(label: tr('Enseigne (catalogue)'), child: TextField(controller: _c['exhibitor_name'], enabled: editable))),
      ]),
      Row(children: [
        Expanded(child: Field(label: tr('Contact'), child: TextField(controller: _c['contact_name'], enabled: editable))),
        const SizedBox(width: 12),
        Expanded(child: Field(label: tr('Téléphone'), child: TextField(controller: _c['contact_phone'], enabled: editable))),
        const SizedBox(width: 12),
        Expanded(child: Field(label: tr('E-mail'), child: TextField(controller: _c['contact_email'], enabled: editable))),
      ]),
      Text(tr('Emplacements'), style: Theme.of(context).textTheme.titleSmall),
      const SizedBox(height: 6),
      if (selectable.isEmpty) Text(tr('Aucun emplacement libre.'), style: TextStyle(color: hint)),
      Wrap(spacing: 6, runSpacing: 6, children: [
        for (final s in selectable)
          FilterChip(
            label: Text(tr('${s['code']} · ${money(toDouble(s['price']), cur)}')),
            tooltip: trn([if (s['category'] != null) '${s['category']}', if (s['area'] != null) '${_n(s['area'])} m²'].join(' · ')),
            selected: _spaces.contains('${s['id']}'),
            onSelected: !editable || _locked ? null : (v) => setState(() => v ? _spaces.add('${s['id']}') : _spaces.remove('${s['id']}')),
          ),
      ]),
      if (pkgs.isNotEmpty) ...[
        const SizedBox(height: 14),
        Text(tr('Sponsors & options'), style: Theme.of(context).textTheme.titleSmall),
        for (final p in pkgs)
          Builder(builder: (_) {
            final q = _items['${p['id']}'] ?? 0;
            final left = p['quantity'] == null ? null : toDouble(p['quantity']).toInt() - evPackageSold(p, except: b['id'] as String?);
            return ListTile(
              dense: true,
              contentPadding: EdgeInsets.zero,
              leading: Icon(p['kind'] == 'sponsor' ? Icons.workspace_premium : Icons.add_task, color: p['kind'] == 'sponsor' ? const Color(0xFF9A6B00) : YColors.blue),
              title: Text(tr('${p['name']}')),
              subtitle: Text(tr('${money(_prices['${p['id']}'] ?? toDouble(p['price']), cur)}${left != null ? ' · reste $left' : ''}')),
              trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                IconButton(onPressed: !editable || _locked || q == 0 ? null : () => setState(() => _items['${p['id']}'] = q - 1), icon: const Icon(Icons.remove_circle_outline)),
                SizedBox(width: 24, child: Text(tr('$q'), textAlign: TextAlign.center, style: const TextStyle(fontWeight: FontWeight.w700))),
                IconButton(onPressed: !editable || _locked || (left != null && q >= left) ? null : () => setState(() => _items['${p['id']}'] = q + 1), icon: const Icon(Icons.add_circle_outline)),
              ]),
            );
          }),
      ],
      const SizedBox(height: 8),
      Row(children: [
        SizedBox(width: 120, child: Field(label: tr('Remise'), child: TextField(controller: _c['discount_percent'], enabled: editable && !_locked, keyboardType: TextInputType.number, decoration: const InputDecoration(suffixText: '%'), onChanged: (_) => setState(() {})))),
        const SizedBox(width: 12),
        if (_status == 'option' || widget.booking == null)
          SizedBox(
            width: 200,
            child: Field(
              label: tr('Option valable jusqu\'au'),
              child: OutlinedButton(
                onPressed: !editable
                    ? null
                    : () async {
                        final d = await pickDate(context, initialDate: DateTime.tryParse(_until ?? '') ?? DateTime.now().add(Duration(days: toDouble(widget.event['option_days'] ?? 7).toInt())), firstDate: DateTime.now().subtract(const Duration(days: 1)), lastDate: DateTime(2100));
                        if (d != null) setState(() => _until = isoDate(d));
                      },
                child: Text(tr(_until == null ? 'Automatique (${_n(widget.event['option_days'] ?? 7)} j)' : dateFr(_until))),
              ),
            ),
          ),
        const Spacer(),
        Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
          Text(tr('${money(preview.totalHt, cur)} HT'), style: TextStyle(color: hint)),
          Text(tr('${money(preview.total, cur)} TTC'), style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
          if (preview.area > 0) Text(tr('${_n(preview.area)} m²'), style: TextStyle(color: hint, fontSize: 12)),
        ]),
      ]),
      Field(label: tr('Notes'), child: TextField(controller: _c['notes'], enabled: editable, maxLines: 2)),
      if (invoices.isNotEmpty) ...[
        Text(tr('Factures'), style: Theme.of(context).textTheme.titleSmall),
        for (final i in invoices)
          ListTile(
            dense: true,
            contentPadding: EdgeInsets.zero,
            leading: Icon(Icons.receipt_long_outlined, color: i['status'] == 'cancelled' ? Colors.grey : YColors.blue),
            title: Text(tr('${i['kind'] == 'deposit' ? 'Acompte' : 'Facture'} ${i['number'] ?? ''}${i['status'] == 'cancelled' ? ' (annulée)' : ''}')),
            subtitle: Text(tr('${money(toDouble(i['total']), cur)} · payé ${money(toDouble(i['amount_paid']), cur)}')),
            trailing: const Icon(Icons.open_in_new, size: 18),
            onTap: () {
              final doc = LocalDb.hasEntity('sales_documents') ? LocalDb.get('sales_documents', '${i['id']}') : null;
              if (doc == null) return toast(context, tr('Facture pas encore synchronisée sur cet appareil.'), error: true);
              Navigator.of(context).push(MaterialPageRoute(builder: (_) => DocumentEditor(config: DocConfig.sale, document: doc, initialType: '${doc['type']}')));
            },
          ),
      ],
      if (_locked) Padding(padding: const EdgeInsets.only(top: 6), child: Text(tr('Réservation entièrement facturée : emplacements, offres et remise ne changent plus.'), style: TextStyle(color: hint, fontSize: 12.5))),
      const SizedBox(height: 12),
      Wrap(spacing: 8, runSpacing: 8, alignment: WrapAlignment.end, children: [
        if (widget.booking != null && evActive.contains(_status) && _canSell(session))
          TextButton(style: TextButton.styleFrom(foregroundColor: YColors.danger), onPressed: () => _save(status: 'cancelled'), child: Text(tr('Annuler la réservation'))),
        if (_status == 'confirmed' && _canSell(session) && b['number'] != null)
          OutlinedButton.icon(
            onPressed: _busy ? null : () => context.read<Session>().api.post('evenements/bookings/${b['id']}/checkin', b['checked_in_at'] != null ? {'undo': true} : null).then((r) async {
                  await LocalDb.put('ev_bookings', asMap(r['data']));
                  if (context.mounted) Navigator.pop(context);
                }).catchError((e) {
                  if (context.mounted) toast(context, errorText(e), error: true);
                }),
            icon: Icon(b['checked_in_at'] != null ? Icons.undo : Icons.how_to_reg),
            label: Text(tr(b['checked_in_at'] != null ? 'Annuler l\'accueil' : 'Exposant arrivé')),
          ),
        if (_status == 'confirmed' && b['number'] != null && session.hasModule('billetterie') && session.can('billetterie.manage'))
          OutlinedButton.icon(onPressed: () => exhibitorBadges(context, widget.event, b), icon: const Icon(Icons.badge_outlined), label: Text(tr('Badges'))),
        if (b['number'] != null && evActive.contains(_status) && _canSell(session))
          OutlinedButton.icon(onPressed: () => editExhibitorProfile(context, b), icon: const Icon(Icons.storefront_outlined), label: Text(tr(b['profile_published'] == true ? 'Fiche exposant (publiée)' : 'Fiche exposant'))),
        if (_status == 'confirmed' && b['number'] != null && b['payment_status'] != 'paid' && _canSell(session))
          FilledButton.tonalIcon(onPressed: _busy ? null : () => markBookingPaid(context, b).then((_) { if (context.mounted) Navigator.pop(context); }), icon: const Icon(Icons.payments_outlined), label: Text(tr('Marquer payé'))),
        if (_status == 'confirmed' && b['balance_invoice_id'] == null && b['number'] != null && _canManage(session))
          OutlinedButton.icon(onPressed: _busy ? null : () => _api('evenements/bookings/${b['id']}/invoice-balance', 'Facture de solde émise.'), icon: const Icon(Icons.request_quote_outlined), label: Text(tr('Facturer le solde'))),
        if (editable) OutlinedButton(onPressed: () => _save(status: _status == 'expired' ? 'option' : null), child: Text(tr(_status == 'expired' ? 'Reprendre l\'option' : 'Enregistrer'))),
        if (editable && _status != 'confirmed') FilledButton.icon(onPressed: () => _save(status: 'confirmed'), icon: const Icon(Icons.check), label: Text(tr('Confirmer'))),
      ]),
    ]);
  }
}
