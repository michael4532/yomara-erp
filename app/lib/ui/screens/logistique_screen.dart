import 'dart:convert';
import 'dart:ui' as ui;

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/local_db.dart';
import '../../core/sync_engine.dart';
import '../../pdf/roadsheet_pdf.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/filter_bar.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

import 'package:yomara_erp/core/safe_launch.dart';
const deliveryStatuses = {
  'draft': 'Brouillon',
  'planned': 'À livrer',
  'in_transit': 'En route',
  'delivered': 'Livrée',
  'failed': 'Échec',
  'returned': 'Retournée',
  'cancelled': 'Annulée',
};
const deliveryColors = {
  'draft': YColors.muted,
  'planned': YColors.blue,
  'in_transit': YColors.warning,
  'delivered': YColors.success,
  'failed': YColors.danger,
  'returned': YColors.danger,
  'cancelled': YColors.muted,
};
const vehicleTypes = {'moto': 'Moto', 'tricycle': 'Tricycle', 'voiture': 'Voiture', 'camionnette': 'Camionnette', 'camion': 'Camion'};
const failureReasons = ['Client absent', 'Téléphone injoignable', 'Adresse introuvable', 'Refusée par le client', 'Paiement non disponible', 'Reportée à la demande du client'];
const timeSlots = ['Matin (8 h – 12 h)', 'Midi (12 h – 14 h)', 'Après-midi (14 h – 18 h)', 'Soir (18 h – 21 h)'];

String _day(DateTime d) => DateFormat('yyyy-MM-dd').format(d);
DateTime _today() {
  final n = DateTime.now();
  return DateTime(n.year, n.month, n.day);
}

DateTime _addDays(DateTime d, int n) => DateTime(d.year, d.month, d.day + n);

/// Livreurs (cache local pour le hors ligne).
Future<List<Map<String, dynamic>>> logistiqueDrivers(Session s) async {
  try {
    final r = await s.api.get('logistique/drivers');
    final users = List<Map<String, dynamic>>.from(r['drivers']);
    await LocalDb.state().put('lg_drivers', users);
    return users;
  } catch (_) {
    return _cachedDrivers();
  }
}

List<Map<String, dynamic>> _cachedDrivers() => [for (final u in (LocalDb.state().get('lg_drivers') as List? ?? const [])) asMap(u)];
String driverName(dynamic id) => _cachedDrivers().where((u) => u['id'] == id).map((u) => '${u['name']}').firstOrNull ?? '';

String deliveryAddress(Map<String, dynamic> d) {
  if (d['latitude'] != null && d['longitude'] != null) return '${d['latitude']},${d['longitude']}';
  return [d['address'], d['city'], 'Côte d\'Ivoire'].where((e) => e != null && '$e'.isNotEmpty).join(', ');
}

/// Itinéraire dans Google Maps (plusieurs arrêts, dans l'ordre).
Future<void> openRoute(List<Map<String, dynamic>> stops) async {
  if (stops.isEmpty) return;
  final dest = Uri.encodeComponent(deliveryAddress(stops.last));
  final way = stops.length > 1 ? '&waypoints=${Uri.encodeComponent(stops.sublist(0, stops.length - 1).take(9).map(deliveryAddress).join('|'))}' : '';
  await safeLaunchUrl(Uri.parse('https://www.google.com/maps/dir/?api=1&travelmode=driving&destination=$dest$way'), mode: LaunchMode.externalApplication);
}

// ======================================================================
// Écran principal : bureau (planification) ou livreur (mes livraisons)
// ======================================================================

class LogistiqueScreen extends StatefulWidget {
  const LogistiqueScreen({super.key});

  @override
  State<LogistiqueScreen> createState() => _LogistiqueScreenState();
}

class _LogistiqueScreenState extends State<LogistiqueScreen> with SingleTickerProviderStateMixin {
  late final bool _manage = context.read<Session>().can('logistique.manage') || context.read<Session>().can('logistique.view');
  late final TabController _tabs = TabController(length: _manage ? 5 : 1, vsync: this);
  DateTime _date = _today();

  @override
  void initState() {
    super.initState();
    logistiqueDrivers(context.read<Session>()).then((_) {
      if (mounted) setState(() {});
    });
  }

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    final canEdit = s.can('logistique.manage');
    if (!_manage) return const DriverHome();
    return Column(children: [
      PageHeader(
        title: tr('Livraisons'),
        subtitle: tr('Tournées, livreurs, suivi client et encaissements à la livraison'),
        actions: [
          if (s.can('logistique.deliver')) OutlinedButton.icon(onPressed: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const Scaffold(body: DriverHome(standalone: true)))), icon: const Icon(Icons.two_wheeler), label: Text(tr('Vue livreur'))),
          if (canEdit) FilledButton.icon(onPressed: () => editDelivery(context, date: _date), icon: const Icon(Icons.add), label: Text(tr('Livraison'))),
        ],
      ),
      TabBar(controller: _tabs, isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [
        Tab(text: tr('Planning du jour')),
        Tab(text: tr('Toutes les livraisons')),
        Tab(text: tr('Encaissements')),
        Tab(text: tr('Statistiques')),
        Tab(text: tr('Zones & véhicules')),
      ]),
      Expanded(
        child: ValueListenableBuilder(
          valueListenable: LocalDb.listen('lg_deliveries'),
          builder: (context, _, _) => ValueListenableBuilder(
            valueListenable: LocalDb.listen('lg_tours'),
            builder: (context, _, _) => TabBarView(controller: _tabs, physics: const NeverScrollableScrollPhysics(), children: [
              DayPlanner(date: _date, canEdit: canEdit, onDate: (d) => setState(() => _date = d)),
              // ignore: prefer_const_constructors
              _AllDeliveries(), // reconstruit à chaque synchronisation
              // ignore: prefer_const_constructors
              _CodTab(),
              const _StatsTab(),
              _SettingsTab(canEdit: canEdit),
            ]),
          ),
        ),
      ),
    ]);
  }
}

// ======================================================================
// Planning du jour : livraisons à attribuer › tournées
// ======================================================================

class DayPlanner extends StatelessWidget {
  const DayPlanner({super.key, required this.date, required this.canEdit, required this.onDate});

  final DateTime date;
  final bool canEdit;
  final void Function(DateTime) onDate;

  Future<void> _assign(BuildContext context, Map<String, dynamic> d, Map<String, dynamic>? tour) async {
    final sync = context.read<SyncEngine>();
    final count = tour == null ? 0 : LocalDb.all('lg_deliveries').where((x) => x['tour_id'] == tour['id']).length;
    await sync.save('lg_deliveries', {
      'id': d['id'],
      'tour_id': tour?['id'],
      'driver_id': tour?['driver_id'] ?? d['driver_id'],
      'tour_seq': count,
      if (tour != null && d['scheduled_date'] != tour['date']) 'scheduled_date': tour['date'],
    });
  }

  @override
  Widget build(BuildContext context) {
    final day = _day(date);
    final all = LocalDb.all('lg_deliveries');
    final tours = LocalDb.all('lg_tours').where((t) => t['date'] == day).toList()..sort((a, b) => '${a['name'] ?? ''}'.compareTo('${b['name'] ?? ''}'));
    final pending = all
        .where((d) => d['tour_id'] == null && ['planned', 'draft', 'failed'].contains(d['status']) && (d['scheduled_date'] == null || '${d['scheduled_date']}'.compareTo(day) <= 0))
        .toList()
      ..sort((a, b) => '${a['scheduled_date'] ?? ''}'.compareTo('${b['scheduled_date'] ?? ''}'));
    final cur = context.read<Session>().currency;
    final hint = Theme.of(context).hintColor;
    final dayDeliveries = all.where((d) => d['scheduled_date'] == day && d['status'] != 'cancelled').toList();

    Widget pendingColumn() => DragTarget<Map<String, dynamic>>(
          onWillAcceptWithDetails: (d) => canEdit && d.data['tour_id'] != null,
          onAcceptWithDetails: (d) => _assign(context, d.data, null),
          builder: (context, cand, _) => Container(
            width: 320,
            margin: const EdgeInsets.only(right: 12),
            decoration: BoxDecoration(
              color: cand.isNotEmpty ? YColors.blue.withValues(alpha: .08) : Theme.of(context).colorScheme.surfaceContainerHighest.withValues(alpha: .45),
              borderRadius: BorderRadius.circular(14),
            ),
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              Padding(
                padding: const EdgeInsets.fromLTRB(14, 12, 14, 8),
                child: Row(children: [
                  const Icon(Icons.inbox_outlined, size: 18),
                  const SizedBox(width: 8),
                  Expanded(child: Text(tr('À attribuer'), style: TextStyle(fontWeight: FontWeight.w700))),
                  StatusChip('${pending.length}', color: YColors.blue),
                ]),
              ),
              Expanded(
                child: pending.isEmpty
                    ? Center(child: Padding(padding: const EdgeInsets.all(16), child: Text(tr('Tout est attribué.'), style: TextStyle(color: hint))))
                    : ListView(padding: const EdgeInsets.fromLTRB(10, 0, 10, 10), children: [
                        for (final d in pending) _draggable(context, d, DeliveryCard(delivery: d, compact: true)),
                      ]),
              ),
            ]),
          ),
        );

    return Column(children: [
      Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
        child: Wrap(spacing: 8, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
          IconButton(onPressed: () => onDate(_addDays(date, -1)), icon: const Icon(Icons.chevron_left)),
          TextButton(
            onPressed: () async {
              final d = await pickDate(context, initialDate: date, firstDate: DateTime(2020), lastDate: DateTime(2100));
              if (d != null) onDate(d);
            },
            child: Text(tr(DateFormat('EEEE d MMMM', I18n.dateLocale).format(date)), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
          ),
          IconButton(onPressed: () => onDate(_addDays(date, 1)), icon: const Icon(Icons.chevron_right)),
          if (date != _today()) TextButton(onPressed: () => onDate(_today()), child: Text(tr('Aujourd\'hui'))),
          const SizedBox(width: 12),
          StatusChip(tr('${dayDeliveries.length} livraison(s)'), color: YColors.blue),
          StatusChip(tr('${dayDeliveries.where((d) => d['status'] == 'delivered').length} livrée(s)'), color: YColors.success),
          if (dayDeliveries.any((d) => ['failed', 'returned'].contains(d['status'])))
            StatusChip(tr('${dayDeliveries.where((d) => ['failed', 'returned'].contains(d['status'])).length} échec(s)'), color: YColors.danger),
          if (canEdit) FilledButton.tonalIcon(onPressed: () => editTour(context, date: date), icon: const Icon(Icons.add_road), label: Text(tr('Tournée'))),
        ]),
      ),
      Expanded(
        child: ListView(scrollDirection: Axis.horizontal, padding: const EdgeInsets.fromLTRB(16, 4, 16, 16), children: [
          pendingColumn(),
          for (final t in tours) _TourColumn(tour: t, deliveries: all.where((d) => d['tour_id'] == t['id']).toList(), canEdit: canEdit, onAssign: (d) => _assign(context, d, t), cur: cur),
          if (tours.isEmpty)
            SizedBox(
              width: 320,
              child: EmptyState(
                icon: Icons.add_road,
                title: tr('Aucune tournée ce jour'),
                message: tr('Créez une tournée (livreur + véhicule), puis glissez-y les livraisons.'),
                action: canEdit ? FilledButton.icon(onPressed: () => editTour(context, date: date), icon: const Icon(Icons.add), label: Text(tr('Tournée'))) : null,
              ),
            ),
        ]),
      ),
    ]);
  }

  Widget _draggable(BuildContext context, Map<String, dynamic> d, Widget card) {
    if (!canEdit) return card;
    final touch = MediaQuery.of(context).size.width < 900;
    final feedback = Material(color: Colors.transparent, child: SizedBox(width: 290, child: Opacity(opacity: .9, child: card)));
    return touch
        ? LongPressDraggable<Map<String, dynamic>>(data: d, feedback: feedback, childWhenDragging: Opacity(opacity: .3, child: card), child: card)
        : Draggable<Map<String, dynamic>>(data: d, feedback: feedback, childWhenDragging: Opacity(opacity: .3, child: card), child: card);
  }
}

class _TourColumn extends StatelessWidget {
  const _TourColumn({required this.tour, required this.deliveries, required this.canEdit, required this.onAssign, required this.cur});

  final Map<String, dynamic> tour;
  final List<Map<String, dynamic>> deliveries;
  final bool canEdit;
  final void Function(Map<String, dynamic>) onAssign;
  final String cur;

  @override
  Widget build(BuildContext context) {
    final rows = [...deliveries]..sort((a, b) => ((a['tour_seq'] as num?) ?? 0).compareTo((b['tour_seq'] as num?) ?? 0));
    final vehicle = LocalDb.get('lg_vehicles', tour['vehicle_id'] as String?);
    final cod = rows.fold<double>(0, (s, d) => s + toDouble(d['cod_amount']));
    final done = rows.where((d) => d['status'] == 'delivered').length;
    final status = '${tour['status'] ?? 'planned'}';
    final color = status == 'done' ? YColors.success : (status == 'in_progress' ? YColors.warning : YColors.blue);
    return DragTarget<Map<String, dynamic>>(
      onWillAcceptWithDetails: (d) => canEdit && d.data['tour_id'] != tour['id'],
      onAcceptWithDetails: (d) => onAssign(d.data),
      builder: (context, cand, _) => Container(
        width: 330,
        margin: const EdgeInsets.only(right: 12),
        decoration: BoxDecoration(
          color: cand.isNotEmpty ? color.withValues(alpha: .1) : Theme.of(context).colorScheme.surfaceContainerHighest.withValues(alpha: .45),
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: cand.isNotEmpty ? color : Colors.transparent, width: 2),
        ),
        clipBehavior: Clip.antiAlias,
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Container(height: 4, color: color),
          Padding(
            padding: const EdgeInsets.fromLTRB(14, 10, 6, 6),
            child: Row(children: [
              CircleAvatar(radius: 16, backgroundColor: color.withValues(alpha: .15), child: Icon(vehicle?['type'] == 'moto' ? Icons.two_wheeler : Icons.local_shipping_outlined, size: 18, color: color)),
              const SizedBox(width: 10),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(tr(tour['name'] ?? driverName(tour['driver_id'])), style: const TextStyle(fontWeight: FontWeight.w700), overflow: TextOverflow.ellipsis),
                  Text(tr([driverName(tour['driver_id']), if (vehicle != null) '${vehicle['name']}'].where((e) => e.isNotEmpty).join(' · ')), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor), overflow: TextOverflow.ellipsis),
                ]),
              ),
              PopupMenuButton<String>(
                onSelected: (v) {
                  if (v == 'route') openRoute(rows.where((d) => !['delivered', 'cancelled'].contains(d['status'])).toList());
                  if (v == 'print') printRoadSheet(session: context.read<Session>(), tour: tour, deliveries: rows, driver: driverName(tour['driver_id']), vehicle: vehicle == null ? null : '${vehicle['name']} ${vehicle['plate'] ?? ''}');
                  if (v == 'edit') editTour(context, tour: tour);
                },
                itemBuilder: (_) => [
                  PopupMenuItem(value: 'route', child: ListTile(dense: true, leading: Icon(Icons.map_outlined), title: Text(tr('Itinéraire (Google Maps)')))),
                  PopupMenuItem(value: 'print', child: ListTile(dense: true, leading: Icon(Icons.print_outlined), title: Text(tr('Feuille de route (PDF)')))),
                  if (canEdit) PopupMenuItem(value: 'edit', child: ListTile(dense: true, leading: Icon(Icons.edit_outlined), title: Text(tr('Modifier la tournée')))),
                ],
              ),
            ]),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(14, 0, 14, 8),
            child: Wrap(spacing: 6, runSpacing: 6, children: [
              StatusChip(tr('$done/${rows.length} livrée(s)'), color: color),
              if (cod > 0) StatusChip(money(cod, cur), icon: Icons.payments_outlined, color: YColors.success),
            ]),
          ),
          Expanded(
            child: rows.isEmpty
                ? Center(child: Text(tr(canEdit ? 'Glissez des livraisons ici' : '—'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12)))
                : canEdit
                    ? ReorderableListView(
                        padding: const EdgeInsets.fromLTRB(10, 0, 10, 10),
                        buildDefaultDragHandles: false,
                        onReorderItem: (a, b) async {
                          final list = [...rows];
                          final x = list.removeAt(a);
                          list.insert(b, x);
                          final sync = context.read<SyncEngine>();
                          for (var i = 0; i < list.length; i++) {
                            if ((list[i]['tour_seq'] as num?)?.toInt() != i) await sync.save('lg_deliveries', {'id': list[i]['id'], 'tour_seq': i});
                          }
                        },
                        children: [
                          for (var i = 0; i < rows.length; i++)
                            Row(key: ValueKey(rows[i]['id']), crossAxisAlignment: CrossAxisAlignment.start, children: [
                              ReorderableDragStartListener(
                                index: i,
                                child: Padding(
                                  padding: const EdgeInsets.only(top: 12, right: 4),
                                  child: CircleAvatar(radius: 11, backgroundColor: color, child: Text(tr('${i + 1}'), style: const TextStyle(fontSize: 11, color: Colors.white, fontWeight: FontWeight.w700))),
                                ),
                              ),
                              Expanded(
                                child: Draggable<Map<String, dynamic>>(
                                  data: rows[i],
                                  feedback: Material(color: Colors.transparent, child: SizedBox(width: 280, child: DeliveryCard(delivery: rows[i], compact: true))),
                                  childWhenDragging: Opacity(opacity: .3, child: DeliveryCard(delivery: rows[i], compact: true)),
                                  child: DeliveryCard(delivery: rows[i], compact: true),
                                ),
                              ),
                            ]),
                        ],
                      )
                    : ListView(padding: const EdgeInsets.fromLTRB(10, 0, 10, 10), children: [for (final d in rows) DeliveryCard(delivery: d, compact: true)]),
          ),
        ]),
      ),
    );
  }
}

class DeliveryCard extends StatelessWidget {
  const DeliveryCard({super.key, required this.delivery, this.compact = false});

  final Map<String, dynamic> delivery;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final d = delivery;
    final cur = context.read<Session>().currency;
    final hint = Theme.of(context).hintColor;
    final status = '${d['status']}';
    final late = d['scheduled_date'] != null && '${d['scheduled_date']}'.compareTo(_day(_today())) < 0 && ['planned', 'in_transit', 'failed'].contains(status);
    final zone = LocalDb.get('lg_zones', d['zone_id'] as String?);
    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => DeliveryScreen(id: d['id'] as String))),
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Expanded(child: Text(tr('${d['recipient_name'] ?? 'Sans nom'}'), style: const TextStyle(fontWeight: FontWeight.w700), overflow: TextOverflow.ellipsis)),
              StatusChip(deliveryStatuses[status] ?? status, color: deliveryColors[status] ?? YColors.muted),
            ]),
            const SizedBox(height: 2),
            Text(tr([d['number'] ?? 'provisoire', if (zone != null) zone['name']].join(' · ')), style: TextStyle(fontSize: 12, color: hint)),
            if ('${d['address'] ?? ''}'.isNotEmpty)
              Padding(
                padding: const EdgeInsets.only(top: 4),
                child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Icon(Icons.place_outlined, size: 14, color: hint),
                  const SizedBox(width: 4),
                  Expanded(child: Text(tr([d['address'], d['city']].where((e) => e != null && '$e'.isNotEmpty).join(', ')), style: const TextStyle(fontSize: 13), maxLines: compact ? 2 : 3, overflow: TextOverflow.ellipsis)),
                ]),
              ),
            const SizedBox(height: 6),
            Wrap(spacing: 6, runSpacing: 6, children: [
              if (d['scheduled_date'] != null) StatusChip(DateFormat('dd/MM').format(DateTime.parse('${d['scheduled_date']}')), icon: Icons.event, color: late ? YColors.danger : YColors.muted),
              if (d['time_slot'] != null) StatusChip('${d['time_slot']}'.split(' (').first, icon: Icons.schedule, color: YColors.muted),
              if (toDouble(d['cod_amount']) > 0)
                StatusChip(money(toDouble(d['cod_collected']) > 0 ? toDouble(d['cod_collected']) : toDouble(d['cod_amount']), cur),
                    icon: Icons.payments_outlined, color: toDouble(d['cod_collected']) > 0 ? YColors.success : YColors.warning),
              if (!compact && d['driver_id'] != null) StatusChip(driverName(d['driver_id']), icon: Icons.person_outline, color: YColors.blue),
              if (status == 'failed' && d['failed_reason'] != null) StatusChip('${d['failed_reason']}', icon: Icons.error_outline, color: YColors.danger),
            ]),
          ]),
        ),
      ),
    );
  }
}

// ======================================================================
// Toutes les livraisons
// ======================================================================

class _AllDeliveries extends StatefulWidget {
  const _AllDeliveries();

  @override
  State<_AllDeliveries> createState() => _AllDeliveriesState();
}

class _AllDeliveriesState extends State<_AllDeliveries> {
  String _q = '';
  String? _status;
  final Map<String, String?> _f = {};
  FilterPeriod? _period;
  int _gen = 0;

  static bool _late(Map<String, dynamic> d) => d['scheduled_date'] != null && '${d['scheduled_date']}'.compareTo(_day(_today())) < 0 && ['planned', 'in_transit', 'failed'].contains(d['status']);

  static bool _statusMatch(Map<String, dynamic> d, String? st) => st == null || st.isEmpty || (st == 'late' ? _late(d) : d['status'] == st);

  @override
  Widget build(BuildContext context) {
    final all = LocalDb.all('lg_deliveries');
    final base = all
        .where((d) => _q.isEmpty || '${d['number']} ${d['recipient_name']} ${d['recipient_phone']} ${d['address']} ${d['city']}'.toLowerCase().contains(_q))
        .where((d) => _f['driver'] == null || '${d['driver_id'] ?? '-'}' == _f['driver'])
        .where((d) => _f['zone'] == null || '${d['zone_id']}' == _f['zone'])
        .where((d) => _f['city'] == null || '${d['city']}' == _f['city'])
        .where((d) => _f['slot'] == null || '${d['time_slot']}' == _f['slot'])
        .where((d) => _f['cod'] == null || switch (_f['cod']) {
              'to_collect' => toDouble(d['cod_amount']) > 0 && toDouble(d['cod_collected']) <= 0,
              'collected' => toDouble(d['cod_collected']) > 0 && d['cod_remitted_at'] == null,
              'remitted' => d['cod_remitted_at'] != null,
              _ => toDouble(d['cod_amount']) <= 0,
            })
        .where((d) => _period == null || _period!.contains(d['scheduled_date']))
        .toList();
    final statuses = {'': 'Toutes', 'late': 'En retard', ...deliveryStatuses};
    final rows = base.where((d) => _statusMatch(d, _status)).toList()
      ..sort((a, b) => '${b['scheduled_date'] ?? ''}${b['number'] ?? ''}'.compareTo('${a['scheduled_date'] ?? ''}${a['number'] ?? ''}'));
    final cur = context.read<Session>().currency;
    return Column(children: [
      const SizedBox(height: 12),
      FilterBar(
        key: ValueKey(_gen),
        onSearch: (v) => setState(() => _q = v.toLowerCase()),
        search: _q,
        searchHint: 'N°, destinataire, téléphone, adresse…',
        statuses: statuses,
        status: _status ?? '',
        onStatus: (v) => setState(() => _status = v.isEmpty ? null : v),
        statusCounts: {for (final k in statuses.keys) k: base.where((d) => _statusMatch(d, k)).length},
        filters: [
          FilterSpec('driver', 'Livreur', {
            ...filterOptions(all, 'driver_id', label: (d) => driverName(d['driver_id']).isEmpty ? '#${d['driver_id']}' : driverName(d['driver_id'])),
            if (all.any((d) => d['driver_id'] == null)) '-': 'Non attribuée',
          }, icon: Icons.person_outline),
          FilterSpec('zone', 'Zone', filterOptions(all, 'zone_id', label: (d) => '${LocalDb.get('lg_zones', d['zone_id'] as String?)?['name'] ?? d['zone_id']}'), icon: Icons.map_outlined),
          FilterSpec('city', 'Ville', filterOptions(all, 'city'), icon: Icons.place_outlined),
          FilterSpec('slot', 'Créneau', filterOptions(all, 'time_slot'), icon: Icons.schedule),
          const FilterSpec('cod', 'Paiement à la livraison', {'to_collect': 'À encaisser', 'collected': 'Encaissé, non remis', 'remitted': 'Remis à la caisse', 'none': 'Sans encaissement'}, icon: Icons.payments_outlined),
        ],
        values: _f,
        onFilter: (k, v) => setState(() => _f[k] = v),
        showPeriod: true,
        period: _period,
        onPeriod: (p) => setState(() => _period = p),
        count: rows.length,
        countLabel: 'livraison',
        onReset: () => setState(() {
          _status = null;
          _q = '';
          _f.clear();
          _period = null;
          _gen++;
        }),
      ),
      Expanded(
        child: rows.isEmpty
            ? EmptyState(icon: Icons.local_shipping_outlined, title: tr('Aucune livraison'), message: tr('Créez une livraison ici ou depuis une commande ou une facture validée.'))
            : ResponsiveList<Map<String, dynamic>>(
                items: rows,
                columns: const ['N°', 'Date', 'Destinataire', 'Adresse', 'Livreur', 'À encaisser', 'Statut'],
                cells: (d) => [
                  Text(tr('${d['number'] ?? 'provisoire'}')),
                  Text(tr(d['scheduled_date'] == null ? '—' : dateFr(d['scheduled_date']))),
                  Text(tr('${d['recipient_name'] ?? ''}')),
                  Text(tr([d['address'], d['city']].where((e) => e != null && '$e'.isNotEmpty).join(', ')), overflow: TextOverflow.ellipsis),
                  Text(tr(driverName(d['driver_id']))),
                  Text(tr(toDouble(d['cod_amount']) > 0 ? money(toDouble(d['cod_amount']), cur) : '—')),
                  StatusChip(deliveryStatuses[d['status']] ?? '', color: deliveryColors[d['status']] ?? YColors.muted),
                ],
                mobileTile: (d) => DeliveryCard(delivery: d),
                onTap: (d) => Navigator.of(context).push(MaterialPageRoute(builder: (_) => DeliveryScreen(id: d['id'] as String))),
              ),
      ),
    ]);
  }
}

// ======================================================================
// Encaissements des livreurs
// ======================================================================

class _CodTab extends StatelessWidget {
  const _CodTab();

  @override
  Widget build(BuildContext context) {
    final cur = context.read<Session>().currency;
    final canEdit = context.read<Session>().can('logistique.manage');
    final pending = LocalDb.all('lg_deliveries').where((d) => d['status'] == 'delivered' && toDouble(d['cod_collected']) > 0 && d['cod_remitted_at'] == null).toList();
    final byDriver = <int, List<Map<String, dynamic>>>{};
    for (final d in pending) {
      byDriver.putIfAbsent(((d['driver_id'] as num?) ?? 0).toInt(), () => []).add(d);
    }
    if (pending.isEmpty) {
      return EmptyState(icon: Icons.payments_outlined, title: tr('Rien à remettre'), message: tr('Les montants encaissés par les livreurs apparaissent ici jusqu\'à leur remise à la caisse.'));
    }
    return ListView(padding: const EdgeInsets.all(16), children: [
      for (final e in byDriver.entries)
        SectionCard(
          title: driverName(e.key).isEmpty ? 'Livreur' : driverName(e.key),
          trailing: canEdit
              ? FilledButton.icon(
                  onPressed: () async {
                    final total = e.value.fold<double>(0, (s, d) => s + toDouble(d['cod_collected']));
                    if (!await confirm(context, tr('Remise à la caisse'), tr('Confirmer la réception de ${money(total, cur)} remis par ${driverName(e.key)} ?'))) return;
                    if (!context.mounted) return;
                    try {
                      final r = await context.read<Session>().api.post('logistique/remit', {'driver_id': e.key, 'ids': e.value.map((d) => d['id']).toList()});
                      if (context.mounted) {
                        toast(context, tr('${r['count']} encaissement(s) remis : ${money(toDouble(r['total']), cur)}.'));
                        await context.read<SyncEngine>().sync();
                      }
                    } catch (err) {
                      if (context.mounted) toast(context, errorText(err), error: true);
                    }
                  },
                  icon: const Icon(Icons.check),
                  label: Text(tr('Remis : ${money(e.value.fold<double>(0, (s, d) => s + toDouble(d['cod_collected'])), cur)}')),
                )
              : null,
          child: Column(children: [
            for (final d in e.value)
              ListTile(
                dense: true,
                contentPadding: EdgeInsets.zero,
                title: Text(tr('${d['number']} · ${d['recipient_name'] ?? ''}')),
                subtitle: Text(tr('Livrée le ${dateTimeFr(d['delivered_at'])} · ${{'cash': 'espèces', 'mobile_money': 'Mobile Money', 'card': 'carte', 'cheque': 'chèque'}[d['cod_method']] ?? 'espèces'}')),
                trailing: Text(tr(money(toDouble(d['cod_collected']), cur)), style: const TextStyle(fontWeight: FontWeight.w700)),
              ),
          ]),
        ),
    ]);
  }
}

// ======================================================================
// Statistiques
// ======================================================================

class _StatsTab extends StatefulWidget {
  const _StatsTab();

  @override
  State<_StatsTab> createState() => _StatsTabState();
}

class _StatsTabState extends State<_StatsTab> {
  Map<String, dynamic>? _d;
  Object? _error;
  late DateTimeRange _range = DateTimeRange(start: DateTime(_today().year, _today().month), end: _today());

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final d = await context.read<Session>().api.get('logistique/stats', query: {'from': _day(_range.start), 'to': _day(_range.end)});
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
    final hint = Theme.of(context).hintColor;
    Widget kpi(String label, String value, IconData icon, {Color color = YColors.blue}) => KpiBox(
          width: 220,
          child: StatTile(label: label, value: value, icon: icon, color: color),
        );
    final drivers = List<Map<String, dynamic>>.from((d['drivers'] as List).map((e) => Map<String, dynamic>.from(e)));
    final failures = Map<String, dynamic>.from(d['failures'] is Map ? d['failures'] : {});
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
          kpi('Livraisons', '${d['total']}', Icons.local_shipping_outlined),
          kpi('Taux de réussite', d['success_rate'] == null ? '—' : '${d['success_rate']} %', Icons.verified_outlined, color: YColors.success),
          kpi('Livrées à la date prévue', d['on_time_rate'] == null ? '—' : '${d['on_time_rate']} %', Icons.schedule),
          kpi('Frais de livraison', money(toDouble(d['fees']), cur), Icons.receipt_long_outlined),
          kpi('Encaissé à la livraison', money(toDouble(d['cod_collected']), cur), Icons.payments_outlined, color: YColors.success),
          kpi('À remettre', money(toDouble(d['cod_pending']), cur), Icons.account_balance_wallet_outlined, color: YColors.warning),
        ]),
        const SizedBox(height: 12),
        SectionCard(
          title: tr('Livreurs'),
          child: drivers.isEmpty
              ? Text('—', style: TextStyle(color: hint))
              : Column(children: [
                  for (final r in drivers)
                    ListTile(
                      dense: true,
                      contentPadding: EdgeInsets.zero,
                      leading: InitialsAvatar('${r['name']}', size: 34),
                      title: Text(tr('${r['name']}')),
                      subtitle: Text(tr('${r['delivered']} livrée(s) sur ${r['total']} · ${r['failed']} échec(s)')),
                      trailing: Text(tr(money(toDouble(r['cod_collected']), cur)), style: const TextStyle(fontWeight: FontWeight.w700)),
                    ),
                ]),
        ),
        if (failures.isNotEmpty) ...[
          const SizedBox(height: 12),
          SectionCard(
            title: tr('Motifs d\'échec'),
            child: Column(children: [
              for (final e in failures.entries)
                Padding(padding: const EdgeInsets.symmetric(vertical: 4), child: Row(children: [Expanded(child: Text(tr(e.key))), Text(tr('${e.value}'), style: const TextStyle(fontWeight: FontWeight.w700))])),
            ]),
          ),
        ],
      ]),
    );
  }
}

// ======================================================================
// Zones et véhicules
// ======================================================================

class _SettingsTab extends StatelessWidget {
  const _SettingsTab({required this.canEdit});

  final bool canEdit;

  @override
  Widget build(BuildContext context) {
    final cur = context.read<Session>().currency;
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('lg_zones'),
      builder: (context, _, _) => ValueListenableBuilder(
        valueListenable: LocalDb.listen('lg_vehicles'),
        builder: (context, _, _) {
          final zones = LocalDb.all('lg_zones')..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));
          final vehicles = LocalDb.all('lg_vehicles')..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));
          return ListView(padding: const EdgeInsets.all(16), children: [
            SectionCard(
              title: tr('Zones de livraison'),
              trailing: canEdit ? TextButton.icon(onPressed: () => _zone(context), icon: const Icon(Icons.add, size: 18), label: Text(tr('Zone'))) : null,
              child: zones.isEmpty
                  ? Text(tr('Définissez vos zones (communes ou quartiers) avec leurs frais et délais : ils se remplissent tout seuls sur les livraisons.'), style: TextStyle(color: Theme.of(context).hintColor))
                  : Column(children: [
                      for (final z in zones)
                        ListTile(
                          contentPadding: EdgeInsets.zero,
                          leading: const Icon(Icons.map_outlined),
                          title: Text(tr('${z['name']}')),
                          subtitle: Text(tr([if ('${z['cities'] ?? ''}'.isNotEmpty) z['cities'], 'délai ${z['delay_days'] ?? 1} j'].join(' · '))),
                          trailing: Text(tr(money(toDouble(z['fee']), cur)), style: const TextStyle(fontWeight: FontWeight.w700)),
                          onTap: canEdit ? () => _zone(context, z) : null,
                        ),
                    ]),
            ),
            const SizedBox(height: 12),
            SectionCard(
              title: tr('Véhicules'),
              trailing: canEdit ? TextButton.icon(onPressed: () => _vehicle(context), icon: const Icon(Icons.add, size: 18), label: Text(tr('Véhicule'))) : null,
              child: vehicles.isEmpty
                  ? Text(tr('Motos, tricycles, camionnettes…'), style: TextStyle(color: Theme.of(context).hintColor))
                  : Column(children: [
                      for (final v in vehicles)
                        ListTile(
                          contentPadding: EdgeInsets.zero,
                          leading: Icon(v['type'] == 'moto' ? Icons.two_wheeler : Icons.local_shipping_outlined),
                          title: Text(tr('${v['name']}')),
                          subtitle: Text(tr([vehicleTypes[v['type']] ?? '', v['plate'] ?? '', if (toDouble(v['capacity_kg']) > 0) '${number(toDouble(v['capacity_kg']))} kg'].where((e) => '$e'.isNotEmpty).join(' · '))),
                          trailing: v['active'] == false ? StatusChip(tr('Inactif')) : null,
                          onTap: canEdit ? () => _vehicle(context, v) : null,
                        ),
                    ]),
            ),
          ]);
        },
      ),
    );
  }

  Future<void> _zone(BuildContext context, [Map<String, dynamic>? z]) async {
    final name = TextEditingController(text: '${z?['name'] ?? ''}');
    final fee = TextEditingController(text: z == null ? '' : '${toDouble(z['fee']).round()}');
    final delay = TextEditingController(text: '${z?['delay_days'] ?? 1}');
    final cities = TextEditingController(text: '${z?['cities'] ?? ''}');
    final sync = context.read<SyncEngine>();
    await showFormDialog(context, title: z == null ? 'Nouvelle zone' : 'Zone', builder: (c) => SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
          Column(mainAxisSize: MainAxisSize.min, children: [
            TextField(controller: name, autofocus: true, decoration: InputDecoration(labelText: tr('Nom (ex. Cocody)'))),
            TextField(controller: cities, decoration: InputDecoration(labelText: tr('Communes / quartiers (séparés par des virgules)'))),
            TextField(controller: fee, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Frais de livraison'))),
            TextField(controller: delay, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Délai standard (jours)'))),
          ]),
          const SizedBox(height: 20),
          Align(
            alignment: Alignment.centerRight,
            child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
              if (z != null)
                TextButton(
                  onPressed: () async {
                    await sync.remove('lg_zones', z['id'] as String);
                    if (c.mounted) Navigator.pop(c);
                  },
                  child: Text(tr('Supprimer'), style: TextStyle(color: YColors.danger)),
                ),
              TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))),
              SaveButton(
                onPressed: () async {
                  if (name.text.trim().isEmpty) return;
                  await sync.save('lg_zones', {
                    'id': z?['id'] ?? newId(),
                    'name': name.text.trim(),
                    'cities': cities.text.trim().isEmpty ? null : cities.text.trim(),
                    'fee': double.tryParse(fee.text.replaceAll(RegExp(r'[\s ]'), '')) ?? 0,
                    'delay_days': int.tryParse(delay.text) ?? 1,
                  });
                  if (c.mounted) Navigator.pop(c);
                },
                child: Text(tr('Enregistrer')),
              ),
            ]),
          ),
        ]),
      ));
  }

  Future<void> _vehicle(BuildContext context, [Map<String, dynamic>? v]) async {
    final name = TextEditingController(text: '${v?['name'] ?? ''}');
    final plate = TextEditingController(text: '${v?['plate'] ?? ''}');
    final cap = TextEditingController(text: v == null || toDouble(v['capacity_kg']) == 0 ? '' : '${toDouble(v['capacity_kg']).round()}');
    var type = '${v?['type'] ?? 'moto'}';
    var active = v?['active'] != false;
    final sync = context.read<SyncEngine>();
    await showFormDialog(context, title: v == null ? 'Nouveau véhicule' : 'Véhicule', builder: (c) => StatefulBuilder(
        builder: (c, set) => SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
            Column(mainAxisSize: MainAxisSize.min, children: [
              TextField(controller: name, autofocus: true, decoration: InputDecoration(labelText: tr('Nom (ex. Moto 1)'))),
              DropdownButtonFormField<String>(
                initialValue: type,
                decoration: InputDecoration(labelText: tr('Type')),
                items: [for (final t in vehicleTypes.entries) DropdownMenuItem(value: t.key, child: Text(tr(t.value)))],
                onChanged: (x) => set(() => type = x ?? type),
              ),
              TextField(controller: plate, decoration: InputDecoration(labelText: tr('Immatriculation'))),
              TextField(controller: cap, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Charge maximale (kg)'))),
              SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('En service')), value: active, onChanged: (x) => set(() => active = x)),
            ]),
            const SizedBox(height: 20),
            Align(
              alignment: Alignment.centerRight,
              child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
                TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))),
                SaveButton(
                  onPressed: () async {
                    if (name.text.trim().isEmpty) return;
                    await sync.save('lg_vehicles', {
                      'id': v?['id'] ?? newId(),
                      'name': name.text.trim(),
                      'type': type,
                      'plate': plate.text.trim().isEmpty ? null : plate.text.trim(),
                      'capacity_kg': double.tryParse(cap.text) ?? 0,
                      'active': active,
                    });
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
// Formulaires : tournée et livraison
// ======================================================================

Future<void> editTour(BuildContext context, {Map<String, dynamic>? tour, DateTime? date}) async {
  final drivers = await logistiqueDrivers(context.read<Session>());
  if (!context.mounted) return;
  final sync = context.read<SyncEngine>();
  final vehicles = LocalDb.all('lg_vehicles').where((v) => v['active'] != false).toList();
  final name = TextEditingController(text: '${tour?['name'] ?? ''}');
  var day = DateTime.tryParse('${tour?['date'] ?? ''}') ?? date ?? _today();
  var driver = (tour?['driver_id'] as num?)?.toInt();
  var vehicle = tour?['vehicle_id'] as String?;
  await showFormDialog(context, title: tour == null ? 'Nouvelle tournée' : 'Tournée', builder: (c) => StatefulBuilder(
      builder: (c, set) => SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
          Column(mainAxisSize: MainAxisSize.min, children: [
            TextField(controller: name, decoration: InputDecoration(labelText: tr('Nom (ex. Cocody matin)'))),
            const SizedBox(height: 8),
            OutlinedButton.icon(
              onPressed: () async {
                final d = await pickDate(c, initialDate: day, firstDate: DateTime(2020), lastDate: DateTime(2100));
                if (d != null) set(() => day = d);
              },
              icon: const Icon(Icons.event),
              label: Text(tr(DateFormat('EEEE d MMMM', I18n.dateLocale).format(day))),
            ),
            DropdownButtonFormField<int>(
              initialValue: drivers.any((u) => u['id'] == driver) ? driver : null,
              isExpanded: true,
              decoration: InputDecoration(labelText: tr('Livreur')),
              items: [for (final u in drivers) DropdownMenuItem(value: u['id'] as int, child: Text(tr('${u['name']}')))],
              onChanged: (x) => set(() => driver = x),
            ),
            DropdownButtonFormField<String?>(
              initialValue: vehicles.any((v) => v['id'] == vehicle) ? vehicle : null,
              isExpanded: true,
              decoration: InputDecoration(labelText: tr('Véhicule')),
              items: [
                const DropdownMenuItem<String?>(value: null, child: Text('—')),
                for (final v in vehicles) DropdownMenuItem<String?>(value: v['id'] as String, child: Text(tr('${v['name']}${v['plate'] != null ? ' · ${v['plate']}' : ''}'))),
              ],
              onChanged: (x) => set(() => vehicle = x),
            ),
          ]),
          const SizedBox(height: 20),
          Align(
            alignment: Alignment.centerRight,
            child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
              if (tour != null)
                TextButton(
                  onPressed: () async {
                    await sync.remove('lg_tours', tour['id'] as String);
                    for (final d in LocalDb.all('lg_deliveries').where((d) => d['tour_id'] == tour['id'])) {
                      await LocalDb.put('lg_deliveries', {...d, 'tour_id': null});
                    }
                    if (c.mounted) Navigator.pop(c);
                  },
                  child: Text(tr('Supprimer'), style: TextStyle(color: YColors.danger)),
                ),
              TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))),
              SaveButton(
                onPressed: () async {
                  final id = tour?['id'] ?? newId();
                  await sync.save('lg_tours', {'id': id, 'name': name.text.trim().isEmpty ? null : name.text.trim(), 'date': _day(day), 'driver_id': driver, 'vehicle_id': vehicle});
                  // Le livreur de la tournée suit sur ses livraisons
                  if (tour != null && driver != tour['driver_id']) {
                    for (final d in LocalDb.all('lg_deliveries').where((d) => d['tour_id'] == id)) {
                      await sync.save('lg_deliveries', {'id': d['id'], 'driver_id': driver});
                    }
                  }
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

/// Livraison : création libre ou à partir d'une commande / facture (lignes, client et reste à payer repris).
Future<void> editDelivery(BuildContext context, {Map<String, dynamic>? delivery, Map<String, dynamic>? fromDocument, DateTime? date}) async {
  final drivers = await logistiqueDrivers(context.read<Session>());
  if (!context.mounted) return;
  await showFormDialog(context, title: delivery == null ? 'Nouvelle livraison' : 'Livraison ${delivery['number'] ?? ''}', builder: (c) => _DeliveryForm(delivery: delivery, fromDocument: fromDocument, date: date, drivers: drivers));
}

class _DeliveryForm extends StatefulWidget {
  const _DeliveryForm({this.delivery, this.fromDocument, this.date, required this.drivers});

  final Map<String, dynamic>? delivery;
  final Map<String, dynamic>? fromDocument;
  final DateTime? date;
  final List<Map<String, dynamic>> drivers;

  @override
  State<_DeliveryForm> createState() => _DeliveryFormState();
}

class _DeliveryFormState extends State<_DeliveryForm> {
  late final Map<String, dynamic> _d = {...?widget.delivery};
  final Map<String, TextEditingController> _c = {};
  late DateTime _date = DateTime.tryParse('${_d['scheduled_date'] ?? ''}') ?? widget.date ?? _today();
  late String? _zone = _d['zone_id'] as String?;
  late int? _driver = (_d['driver_id'] as num?)?.toInt();
  late String? _slot = _d['time_slot'] as String?;
  late String? _doc = _d['source_document_id'] as String?;
  late String? _partner = _d['partner_id'] as String?;
  late bool _notify = _d['notify'] != false;
  late List<Map<String, dynamic>> _items = [for (final i in (_d['items'] as List? ?? const [])) asMap(i)];

  TextEditingController c(String k, [String? init]) => _c.putIfAbsent(k, () => TextEditingController(text: init ?? (_d[k] == null ? '' : '${_d[k]}')));

  @override
  void initState() {
    super.initState();
    for (final k in ['fee', 'cod_amount', 'weight_kg']) {
      final v = toDouble(_d[k]);
      c(k, v == 0 ? '' : (v == v.roundToDouble() ? '${v.round()}' : '$v'));
    }
    c('packages', '${_d['packages'] ?? 1}');
    if (widget.fromDocument != null) _applyDocument(widget.fromDocument!);
  }

  void _applyDocument(Map<String, dynamic> doc) {
    final partner = LocalDb.get('partners', doc['partner_id'] as String?);
    _doc = doc['id'] as String;
    _partner = doc['partner_id'] as String?;
    c('recipient_name').text = '${partner?['name'] ?? doc['partner_name'] ?? ''}';
    c('recipient_phone').text = '${partner?['phone'] ?? ''}';
    c('address').text = '${partner?['address'] ?? ''}';
    c('city').text = '${partner?['city'] ?? ''}';
    _items = [
      for (final l in (doc['lines'] as List? ?? const []))
        {'description': (l as Map)['description'], 'quantity': toDouble(l['quantity']), 'unit': l['unit']},
    ];
    if (doc['type'] == 'invoice') {
      final due = toDouble(doc['total']) - toDouble(doc['amount_paid']);
      c('cod_amount').text = due > 0 ? '${due.round()}' : '';
    }
    _guessZone();
  }

  void _guessZone() {
    final city = c('city').text.toLowerCase().trim();
    if (city.isEmpty || _zone != null) return;
    for (final z in LocalDb.all('lg_zones')) {
      final names = ['${z['name']}', ...'${z['cities'] ?? ''}'.split(',')].map((e) => e.trim().toLowerCase()).where((e) => e.isNotEmpty);
      if (names.any((n) => city.contains(n) || n.contains(city))) {
        _setZone(z);
        return;
      }
    }
  }

  void _setZone(Map<String, dynamic>? z) {
    _zone = z?['id'] as String?;
    if (z != null) {
      if (c('fee').text.isEmpty) c('fee').text = '${toDouble(z['fee']).round()}';
      if (widget.delivery == null) _date = DateTime(_today().year, _today().month, _today().day + ((z['delay_days'] as num?) ?? 1).toInt());
    }
  }

  Future<void> _save() async {
    final sync = context.read<SyncEngine>();
    double n(String k) => double.tryParse(c(k).text.replaceAll(RegExp(r'[\s ]'), '').replaceAll(',', '.')) ?? 0;
    try {
      await sync.save('lg_deliveries', {
        'id': _d['id'] ?? newId(),
        'source_document_id': _doc,
        'partner_id': _partner,
        'recipient_name': c('recipient_name').text.trim(),
        'recipient_phone': c('recipient_phone').text.trim().isEmpty ? null : c('recipient_phone').text.trim(),
        'address': c('address').text.trim().isEmpty ? null : c('address').text.trim(),
        'city': c('city').text.trim().isEmpty ? null : c('city').text.trim(),
        'zone_id': _zone,
        'scheduled_date': _day(_date),
        'time_slot': _slot,
        'driver_id': _driver,
        'items': _items.where((i) => '${i['description'] ?? ''}'.trim().isNotEmpty).toList(),
        'packages': int.tryParse(c('packages').text) ?? 1,
        'weight_kg': n('weight_kg'),
        'fee': n('fee'),
        'cod_amount': n('cod_amount'),
        'notes': c('notes').text.trim().isEmpty ? null : c('notes').text.trim(),
        'notify': _notify,
        if (widget.delivery == null) 'status': 'planned',
      });
      if (mounted) Navigator.pop(context);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final zones = LocalDb.all('lg_zones')..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));
    final docs = LocalDb.all('sales_documents').where((d) => ['order', 'invoice'].contains(d['type']) && d['status'] == 'validated').toList()
      ..sort((a, b) => '${b['date']}'.compareTo('${a['date']}'));
    final doc = LocalDb.get('sales_documents', _doc);
    return ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), children: [
      if (widget.delivery == null)
        Field(
          label: tr('À partir d\'une commande ou facture (facultatif)'),
          child: DropdownButtonFormField<String?>(
            initialValue: _doc,
            isExpanded: true,
            items: [
              DropdownMenuItem<String?>(value: null, child: Text(tr('— Livraison libre —'))),
              for (final d in docs.take(200)) DropdownMenuItem<String?>(value: d['id'] as String, child: Text(tr('${d['number'] ?? 'en attente'} · ${d['partner_name'] ?? ''} · ${money(toDouble(d['total']), context.read<Session>().currency)}'), overflow: TextOverflow.ellipsis)),
            ],
            onChanged: (v) => setState(() {
              if (v == null) {
                _doc = null;
              } else {
                _applyDocument(LocalDb.get('sales_documents', v)!);
              }
            }),
          ),
        )
      else if (doc != null)
        Padding(padding: const EdgeInsets.only(bottom: 12), child: Text(tr('Document : ${doc['number'] ?? ''} · ${doc['partner_name'] ?? ''}'), style: const TextStyle(fontWeight: FontWeight.w600))),
      Row(children: [
        Expanded(child: Field(label: tr('Destinataire'), child: TextField(controller: c('recipient_name')))),
        const SizedBox(width: 12),
        Expanded(child: Field(label: tr('Téléphone'), child: TextField(controller: c('recipient_phone'), keyboardType: TextInputType.phone))),
      ]),
      Field(label: tr('Adresse / repères'), child: TextField(controller: c('address'), maxLines: 2, decoration: InputDecoration(hintText: tr('Ex. Angré 8e tranche, derrière la pharmacie')))),
      Row(children: [
        Expanded(child: Field(label: tr('Commune / ville'), child: TextField(controller: c('city'), onChanged: (_) => setState(_guessZone)))),
        const SizedBox(width: 12),
        Expanded(
          child: Field(
            label: tr('Zone'),
            child: DropdownButtonFormField<String?>(
              key: ValueKey('zone-$_zone'),
              initialValue: zones.any((z) => z['id'] == _zone) ? _zone : null,
              isExpanded: true,
              items: [
                const DropdownMenuItem<String?>(value: null, child: Text('—')),
                for (final z in zones) DropdownMenuItem<String?>(value: z['id'] as String, child: Text(tr('${z['name']} · ${money(toDouble(z['fee']), context.read<Session>().currency)}'), overflow: TextOverflow.ellipsis)),
              ],
              onChanged: (v) => setState(() => _setZone(v == null ? null : LocalDb.get('lg_zones', v))),
            ),
          ),
        ),
      ]),
      Row(children: [
        Expanded(
          child: Field(
            label: tr('Date'),
            child: OutlinedButton.icon(
              onPressed: () async {
                final d = await pickDate(context, initialDate: _date, firstDate: DateTime(2020), lastDate: DateTime(2100));
                if (d != null) setState(() => _date = d);
              },
              icon: const Icon(Icons.event, size: 18),
              label: Text(tr(DateFormat('EEE dd/MM/yyyy', I18n.dateLocale).format(_date))),
            ),
          ),
        ),
        const SizedBox(width: 12),
        Expanded(
          child: Field(
            label: tr('Créneau'),
            child: DropdownButtonFormField<String?>(
              initialValue: _slot,
              isExpanded: true,
              items: [DropdownMenuItem<String?>(value: null, child: Text(tr('Dans la journée'))), for (final s in timeSlots) DropdownMenuItem<String?>(value: s, child: Text(tr(s)))],
              onChanged: (v) => setState(() => _slot = v),
            ),
          ),
        ),
      ]),
      Field(
        label: tr('Livreur'),
        child: DropdownButtonFormField<int?>(
          initialValue: widget.drivers.any((u) => u['id'] == _driver) ? _driver : null,
          isExpanded: true,
          items: [DropdownMenuItem<int?>(value: null, child: Text(tr('À attribuer (via une tournée)'))), for (final u in widget.drivers) DropdownMenuItem<int?>(value: u['id'] as int, child: Text(tr('${u['name']}')))],
          onChanged: (v) => setState(() => _driver = v),
        ),
      ),
      Field(
        label: tr('Contenu'),
        child: Column(children: [
          for (var i = 0; i < _items.length; i++)
            Row(children: [
              SizedBox(
                width: 70,
                child: TextFormField(
                  initialValue: number(toDouble(_items[i]['quantity'] ?? 1), decimals: toDouble(_items[i]['quantity'] ?? 1) % 1 == 0 ? 0 : 2),
                  keyboardType: TextInputType.number,
                  decoration: InputDecoration(isDense: true, labelText: tr('Qté')),
                  onChanged: (v) => _items[i]['quantity'] = double.tryParse(v.replaceAll(',', '.')) ?? 1,
                ),
              ),
              const SizedBox(width: 8),
              Expanded(child: TextFormField(initialValue: '${_items[i]['description'] ?? ''}', decoration: InputDecoration(isDense: true, labelText: tr('Article')), onChanged: (v) => _items[i]['description'] = v)),
              IconButton(onPressed: () => setState(() => _items.removeAt(i)), icon: const Icon(Icons.close, size: 18)),
            ]),
          Align(alignment: Alignment.centerLeft, child: TextButton.icon(onPressed: () => setState(() => _items.add({'description': '', 'quantity': 1})), icon: const Icon(Icons.add), label: Text(tr('Article')))),
        ]),
      ),
      Row(children: [
        Expanded(child: Field(label: tr('Colis'), child: TextField(controller: c('packages'), keyboardType: TextInputType.number))),
        const SizedBox(width: 12),
        Expanded(child: Field(label: tr('Poids (kg)'), child: TextField(controller: c('weight_kg'), keyboardType: TextInputType.number))),
        const SizedBox(width: 12),
        Expanded(child: Field(label: tr('Frais de livraison'), child: TextField(controller: c('fee'), keyboardType: TextInputType.number))),
      ]),
      Field(
        label: tr('À encaisser à la livraison'),
        child: TextField(controller: c('cod_amount'), keyboardType: TextInputType.number, decoration: InputDecoration(hintText: tr('0 si déjà payé'), helperText: tr('Encaissé par le livreur : le paiement s\'inscrit sur la facture.'))),
      ),
      Field(label: tr('Instructions au livreur'), child: TextField(controller: c('notes'), maxLines: 2)),
      SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Prévenir le client (WhatsApp, SMS ou e-mail)')), subtitle: Text(tr('Départ, livraison et échec, avec le lien de suivi')), value: _notify, onChanged: (v) => setState(() => _notify = v)),
      const SizedBox(height: 8),
      Row(children: [
        if (widget.delivery != null && context.read<Session>().can('logistique.manage'))
          TextButton(
            onPressed: () async {
              if (!await confirm(context, tr('Supprimer la livraison ?'), '${_d['number'] ?? ''}', danger: true, ok: tr('Supprimer'))) return;
              if (!context.mounted) return;
              await context.read<SyncEngine>().remove('lg_deliveries', _d['id'] as String);
              if (context.mounted) Navigator.of(context).popUntil((r) => r.isFirst);
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
// Fiche livraison
// ======================================================================

class DeliveryScreen extends StatefulWidget {
  const DeliveryScreen({super.key, required this.id});

  final String id;

  @override
  State<DeliveryScreen> createState() => _DeliveryScreenState();
}

class _DeliveryScreenState extends State<DeliveryScreen> {
  List<Map<String, dynamic>>? _events;
  String? _tracking;

  @override
  void initState() {
    super.initState();
    _loadEvents();
  }

  Future<void> _loadEvents() async {
    try {
      final r = await context.read<Session>().api.get('logistique/deliveries/${widget.id}/events');
      if (mounted) setState(() => (_events = List<Map<String, dynamic>>.from((r['data'] as List).map((e) => Map<String, dynamic>.from(e))), _tracking = '${r['tracking_url']}'));
    } catch (_) {}
  }

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('lg_deliveries'),
      builder: (context, _, _) {
        final d = LocalDb.get('lg_deliveries', widget.id);
        if (d == null) return Scaffold(appBar: AppBar(), body: EmptyState(icon: Icons.local_shipping_outlined, title: tr('Livraison introuvable'), message: ''));
        final cur = s.currency;
        final status = '${d['status']}';
        final mine = d['driver_id'] == s.user['id'];
        final canAct = s.can('logistique.manage') || (mine && s.can('logistique.deliver'));
        final tracking = _tracking ?? (d['tracking_token'] != null ? '${s.serverUrl}/t/${d['tracking_token']}' : null);
        final doc = LocalDb.get('sales_documents', d['source_document_id'] as String?);
        Widget row(String k, String? v) => v == null || v.isEmpty
            ? const SizedBox.shrink()
            : Padding(padding: const EdgeInsets.symmetric(vertical: 4), child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [SizedBox(width: 150, child: Text(tr(k), style: TextStyle(color: Theme.of(context).hintColor))), Expanded(child: Text(tr(v)))]));
        return Scaffold(
          appBar: AppBar(
            title: Text(tr('Livraison ${d['number'] ?? '(provisoire)'}')),
            actions: [
              Padding(padding: const EdgeInsets.only(right: 8), child: Center(child: StatusChip(deliveryStatuses[status] ?? status, color: deliveryColors[status] ?? YColors.muted))),
              if (s.can('logistique.manage')) IconButton(tooltip: tr('Modifier'), onPressed: () => editDelivery(context, delivery: d), icon: const Icon(Icons.edit_outlined)),
            ],
          ),
          body: ListView(padding: const EdgeInsets.all(16), children: [
            if (canAct) DeliveryActions(delivery: d),
            const SizedBox(height: 12),
            SectionCard(
              title: '${d['recipient_name'] ?? ''}',
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                row('Téléphone', d['recipient_phone'] as String?),
                row('Adresse', [d['address'], d['city']].where((e) => e != null && '$e'.isNotEmpty).join(', ')),
                row('Zone', LocalDb.get('lg_zones', d['zone_id'] as String?)?['name'] as String?),
                row('Prévue le', d['scheduled_date'] == null ? null : '${dateFr(d['scheduled_date'])}${d['time_slot'] != null ? ' · ${d['time_slot']}' : ''}'),
                row('Livreur', driverName(d['driver_id'])),
                row('Document', doc == null ? null : '${doc['number'] ?? ''} · ${money(toDouble(doc['total']), cur)}'),
                row('Frais', toDouble(d['fee']) > 0 ? money(toDouble(d['fee']), cur) : null),
                row('À encaisser', toDouble(d['cod_amount']) > 0 ? money(toDouble(d['cod_amount']), cur) : null),
                row('Encaissé', toDouble(d['cod_collected']) > 0 ? '${money(toDouble(d['cod_collected']), cur)}${d['cod_remitted_at'] != null ? ' · remis à la caisse' : ''}' : null),
                row('Instructions', d['notes'] as String?),
                row('Motif d\'échec', d['failed_reason'] as String?),
                const SizedBox(height: 8),
                Wrap(spacing: 8, runSpacing: 8, children: [
                  if (d['recipient_phone'] != null) OutlinedButton.icon(onPressed: () => safeLaunchUrl(Uri.parse('tel:${d['recipient_phone']}')), icon: const Icon(Icons.call), label: Text(tr('Appeler'))),
                  if (d['recipient_phone'] != null)
                    OutlinedButton.icon(
                      onPressed: () => safeLaunchUrl(Uri.parse('https://wa.me/${'${d['recipient_phone']}'.replaceAll(RegExp(r'\D'), '').replaceFirst(RegExp(r'^0'), '2250')}'), mode: LaunchMode.externalApplication),
                      icon: const Icon(Icons.chat_outlined),
                      label: Text(tr('WhatsApp')),
                    ),
                  OutlinedButton.icon(onPressed: () => openRoute([d]), icon: const Icon(Icons.navigation_outlined), label: Text(tr('Itinéraire'))),
                  if (tracking != null)
                    OutlinedButton.icon(
                      onPressed: () {
                        Clipboard.setData(ClipboardData(text: tracking));
                        toast(context, tr('Lien de suivi copié.'));
                      },
                      icon: const Icon(Icons.link),
                      label: Text(tr('Lien de suivi')),
                    ),
                ]),
              ]),
            ),
            if ((d['items'] as List? ?? const []).isNotEmpty) ...[
              const SizedBox(height: 12),
              SectionCard(
                title: tr('Contenu${(d['packages'] ?? 1) != 1 ? ' · ${d['packages']} colis' : ''}'),
                child: Column(children: [
                  for (final it in (d['items'] as List))
                    Padding(
                      padding: const EdgeInsets.symmetric(vertical: 3),
                      child: Row(children: [
                        SizedBox(width: 60, child: Text(tr(number(toDouble((it as Map)['quantity'] ?? 1), decimals: toDouble(it['quantity'] ?? 1) % 1 == 0 ? 0 : 2)), style: const TextStyle(fontWeight: FontWeight.w700))),
                        Expanded(child: Text(tr('${it['description']}${it['unit'] != null ? ' (${it['unit']})' : ''}'))),
                      ]),
                    ),
                ]),
              ),
            ],
            if (status == 'delivered') ...[
              const SizedBox(height: 12),
              SectionCard(
                title: tr('Preuve de livraison'),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  row('Livrée le', dateTimeFr(d['delivered_at'])),
                  row('Reçue par', d['signed_by'] as String?),
                  if ('${d['signature'] ?? ''}'.startsWith('data:image'))
                    Container(
                      margin: const EdgeInsets.only(top: 8),
                      height: 120,
                      decoration: BoxDecoration(border: Border.all(color: YColors.line), borderRadius: BorderRadius.circular(8), color: Colors.white),
                      child: Image.memory(base64Decode('${d['signature']}'.split(',').last), fit: BoxFit.contain),
                    ),
                  if (d['photo'] != null) Padding(padding: const EdgeInsets.only(top: 8), child: ClipRRect(borderRadius: BorderRadius.circular(8), child: Image.network('${d['photo']}', height: 200))),
                ]),
              ),
            ],
            const SizedBox(height: 12),
            SectionCard(
              title: tr('Historique'),
              child: _events == null
                  ? Text(tr('Disponible avec la connexion.'), style: TextStyle(color: Theme.of(context).hintColor))
                  : Column(children: [
                      for (final e in _events!.reversed)
                        ListTile(
                          dense: true,
                          contentPadding: EdgeInsets.zero,
                          leading: Icon(Icons.circle, size: 12, color: deliveryColors[e['status']] ?? YColors.muted),
                          title: Text(tr('${deliveryStatuses[e['status']] ?? e['status']}${e['note'] != null ? ' — ${e['note']}' : ''}')),
                          subtitle: Text(tr('${dateTimeFr(e['created_at'])}${e['user'] != null ? ' · ${e['user']}' : ''}')),
                        ),
                    ]),
            ),
          ]),
        );
      },
    );
  }
}

/// Boutons d'action selon le statut (bureau ou livreur).
class DeliveryActions extends StatelessWidget {
  const DeliveryActions({super.key, required this.delivery});

  final Map<String, dynamic> delivery;

  Future<void> _set(BuildContext context, Map<String, dynamic> data) async {
    try {
      await context.read<SyncEngine>().save('lg_deliveries', {'id': delivery['id'], ...data});
    } catch (e) {
      if (context.mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _fail(BuildContext context) async {
    final other = TextEditingController();
    String? reason;
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => StatefulBuilder(
        builder: (c, set) => YAlertDialog(
          title: Text(tr('Livraison non effectuée')),
          content: SizedBox(
            width: 420,
            child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              Wrap(spacing: 6, runSpacing: 6, children: [for (final r in failureReasons) ChoiceChip(label: Text(tr(r)), selected: reason == r, onSelected: (_) => set(() => reason = r))]),
              const SizedBox(height: 8),
              TextField(controller: other, decoration: InputDecoration(labelText: tr('Autre motif / précision'))),
            ]),
          ),
          actions: [TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Valider')))],
        ),
      ),
    );
    if (ok != true || !context.mounted) return;
    final why = [reason, other.text.trim()].where((e) => e != null && e.isNotEmpty).join(' — ');
    await _set(context, {'status': 'failed', 'failed_reason': why.isEmpty ? 'Non précisé' : why});
  }

  @override
  Widget build(BuildContext context) {
    final status = '${delivery['status']}';
    final manage = context.read<Session>().can('logistique.manage');
    return Wrap(spacing: 8, runSpacing: 8, children: [
      if (['planned', 'failed'].contains(status))
        FilledButton.icon(onPressed: () => _set(context, {'status': 'in_transit'}), icon: const Icon(Icons.two_wheeler), label: Text(tr('Je pars livrer'))),
      if (['planned', 'in_transit', 'failed'].contains(status))
        FilledButton.icon(
          style: FilledButton.styleFrom(backgroundColor: YColors.success),
          onPressed: () => showProofSheet(context, delivery),
          icon: const Icon(Icons.check_circle_outline),
          label: Text(tr('Livrée')),
        ),
      if (['planned', 'in_transit'].contains(status))
        OutlinedButton.icon(
          style: OutlinedButton.styleFrom(foregroundColor: YColors.danger),
          onPressed: () => _fail(context),
          icon: const Icon(Icons.report_gmailerrorred),
          label: Text(tr('Échec')),
        ),
      if (manage && status == 'failed') OutlinedButton.icon(onPressed: () => _set(context, {'status': 'planned', 'scheduled_date': _day(_addDays(_today(), 1))}), icon: const Icon(Icons.event_repeat), label: Text(tr('Reprogrammer demain'))),
      if (manage && status == 'failed') OutlinedButton.icon(onPressed: () => _set(context, {'status': 'returned'}), icon: const Icon(Icons.undo), label: Text(tr('Retour en stock'))),
      if (manage && !['delivered', 'cancelled'].contains(status))
        TextButton(onPressed: () => _set(context, {'status': 'cancelled'}), child: Text(tr('Annuler la livraison'))),
    ]);
  }
}

// ======================================================================
// Preuve de livraison : signature, nom, encaissement, photo
// ======================================================================

Future<void> showProofSheet(BuildContext context, Map<String, dynamic> d) async {
  await showModalBottomSheet(
    context: context,
    isScrollControlled: true,
    showDragHandle: true,
    builder: (c) => Padding(padding: EdgeInsets.only(bottom: MediaQuery.of(c).viewInsets.bottom), child: _ProofForm(delivery: d)),
  );
}

class _ProofForm extends StatefulWidget {
  const _ProofForm({required this.delivery});

  final Map<String, dynamic> delivery;

  @override
  State<_ProofForm> createState() => _ProofFormState();
}

class _ProofFormState extends State<_ProofForm> {
  final _pad = GlobalKey<SignaturePadState>();
  late final _name = TextEditingController(text: '${widget.delivery['recipient_name'] ?? ''}');
  late final _cod = TextEditingController(text: toDouble(widget.delivery['cod_amount']) > 0 ? '${toDouble(widget.delivery['cod_amount']).round()}' : '');
  String _method = 'cash';
  PlatformFile? _photo;
  bool _busy = false;

  Future<void> _save() async {
    setState(() => _busy = true);
    final sync = context.read<SyncEngine>();
    final api = context.read<Session>().api;
    final signature = await _pad.currentState?.exportPng();
    try {
      await sync.save('lg_deliveries', {
        'id': widget.delivery['id'],
        'status': 'delivered',
        'signed_by': _name.text.trim().isEmpty ? null : _name.text.trim(),
        'signature': signature,
        'delivered_at': DateTime.now().toUtc().toIso8601String(),
        if (toDouble(widget.delivery['cod_amount']) > 0) 'cod_collected': double.tryParse(_cod.text.replaceAll(RegExp(r'[\s ]'), '')) ?? 0,
        if (toDouble(widget.delivery['cod_amount']) > 0) 'cod_method': _method,
      });
      if (_photo != null) {
        // La photo part dès que possible (nécessite la connexion)
        try {
          await sync.sync();
          await api.upload('logistique/deliveries/${widget.delivery['id']}/photo', 'photo', await _photo!.readAsBytes(), _photo!.name);
          await sync.sync();
        } catch (_) {
          if (mounted) toast(context, tr('Livraison enregistrée ; la photo sera à renvoyer avec la connexion.'), error: true);
        }
      }
      if (mounted) {
        Navigator.pop(context);
        toast(context, tr('Livraison confirmée.'));
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final cod = toDouble(widget.delivery['cod_amount']);
    final cur = context.read<Session>().currency;
    return SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(20, 0, 20, 20),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text(tr('Confirmer la livraison ${widget.delivery['number'] ?? ''}'), style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
        const SizedBox(height: 12),
        TextField(controller: _name, decoration: InputDecoration(labelText: tr('Reçue par'))),
        const SizedBox(height: 12),
        Row(children: [
          Expanded(child: Text(tr('Signature du client'), style: TextStyle(fontWeight: FontWeight.w600))),
          TextButton(onPressed: () => _pad.currentState?.clear(), child: Text(tr('Effacer'))),
        ]),
        SignaturePad(key: _pad),
        if (cod > 0) ...[
          const SizedBox(height: 12),
          Text(tr('À encaisser : ${money(cod, cur)}'), style: const TextStyle(fontWeight: FontWeight.w700)),
          const SizedBox(height: 8),
          Row(children: [
            Expanded(child: TextField(controller: _cod, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Montant reçu')))),
            const SizedBox(width: 12),
            DropdownButton<String>(
              value: _method,
              items: [
                DropdownMenuItem(value: 'cash', child: Text(tr('Espèces'))),
                DropdownMenuItem(value: 'mobile_money', child: Text(tr('Mobile Money'))),
                DropdownMenuItem(value: 'card', child: Text(tr('Carte'))),
                DropdownMenuItem(value: 'cheque', child: Text(tr('Chèque'))),
              ],
              onChanged: (v) => setState(() => _method = v ?? _method),
            ),
          ]),
        ],
        const SizedBox(height: 12),
        OutlinedButton.icon(
          onPressed: () async {
            final r = await FilePicker.pickFiles(type: FileType.image);
            if (r.isNotEmpty) setState(() => _photo = r.first);
          },
          icon: const Icon(Icons.photo_camera_outlined),
          label: Text(tr(_photo == null ? 'Ajouter une photo (facultatif)' : _photo!.name)),
        ),
        const SizedBox(height: 16),
        FilledButton.icon(
          style: FilledButton.styleFrom(backgroundColor: YColors.success, minimumSize: const Size.fromHeight(48)),
          onPressed: _busy ? null : _save,
          icon: const Icon(Icons.check),
          label: Text(tr(_busy ? 'Enregistrement…' : 'Confirmer la livraison')),
        ),
      ]),
    );
  }
}

/// Zone de signature au doigt ou à la souris, exportée en PNG.
class SignaturePad extends StatefulWidget {
  const SignaturePad({super.key, this.height = 180});

  final double height;

  @override
  State<SignaturePad> createState() => SignaturePadState();
}

class SignaturePadState extends State<SignaturePad> {
  final List<List<Offset>> _strokes = [];
  Size _size = Size.zero;

  void clear() => setState(_strokes.clear);

  bool get isEmpty => _strokes.isEmpty;

  /// PNG en data URI (null si rien n'a été signé).
  Future<String?> exportPng() async {
    if (_strokes.isEmpty || _size == Size.zero) return null;
    final recorder = ui.PictureRecorder();
    final canvas = Canvas(recorder);
    canvas.drawRect(Offset.zero & _size, Paint()..color = Colors.white);
    _SignaturePainter(_strokes).paint(canvas, _size);
    final img = await recorder.endRecording().toImage(_size.width.round(), _size.height.round());
    final bytes = await img.toByteData(format: ui.ImageByteFormat.png);
    if (bytes == null) return null;
    return 'data:image/png;base64,${base64Encode(bytes.buffer.asUint8List())}';
  }

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(builder: (context, c) {
      _size = Size(c.maxWidth, widget.height);
      return Container(
        height: widget.height,
        decoration: BoxDecoration(color: Colors.white, border: Border.all(color: YColors.line, width: 1.5), borderRadius: BorderRadius.circular(10)),
        child: ClipRRect(
          borderRadius: BorderRadius.circular(10),
          child: GestureDetector(
            onPanStart: (d) => setState(() => _strokes.add([d.localPosition])),
            onPanUpdate: (d) => setState(() => _strokes.last.add(d.localPosition)),
            child: CustomPaint(
              painter: _SignaturePainter(_strokes),
              size: Size(c.maxWidth, widget.height),
              child: _strokes.isEmpty ? Center(child: Text(tr('Signez ici'), style: TextStyle(color: Theme.of(context).hintColor))) : null,
            ),
          ),
        ),
      );
    });
  }
}

class _SignaturePainter extends CustomPainter {
  _SignaturePainter(this.strokes);

  final List<List<Offset>> strokes;

  @override
  void paint(Canvas canvas, Size size) {
    final p = Paint()
      ..color = YColors.night
      ..strokeWidth = 2.6
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round
      ..style = PaintingStyle.stroke;
    for (final s in strokes) {
      if (s.length == 1) {
        canvas.drawCircle(s.first, 1.4, p..style = PaintingStyle.fill);
        p.style = PaintingStyle.stroke;
        continue;
      }
      final path = Path()..moveTo(s.first.dx, s.first.dy);
      for (final o in s.skip(1)) {
        path.lineTo(o.dx, o.dy);
      }
      canvas.drawPath(path, p);
    }
  }

  @override
  bool shouldRepaint(covariant _SignaturePainter old) => true;
}

// ======================================================================
// Vue livreur : mes livraisons du jour (hors ligne)
// ======================================================================

class DriverHome extends StatelessWidget {
  const DriverHome({super.key, this.standalone = false});

  final bool standalone;

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    final me = s.user['id'];
    final cur = s.currency;
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('lg_deliveries'),
      builder: (context, _, _) {
        final today = _day(_today());
        final mine = LocalDb.all('lg_deliveries').where((d) => d['driver_id'] == me && d['status'] != 'cancelled').toList()
          ..sort((a, b) {
            final x = '${a['scheduled_date'] ?? ''}'.compareTo('${b['scheduled_date'] ?? ''}');
            return x != 0 ? x : ((a['tour_seq'] as num?) ?? 0).compareTo((b['tour_seq'] as num?) ?? 0);
          });
        final todo = mine.where((d) => ['planned', 'in_transit', 'failed'].contains(d['status']) && '${d['scheduled_date'] ?? today}'.compareTo(today) <= 0).toList();
        final later = mine.where((d) => d['status'] == 'planned' && '${d['scheduled_date'] ?? ''}'.compareTo(today) > 0).toList();
        final doneToday = mine.where((d) => d['status'] == 'delivered' && '${d['delivered_at'] ?? ''}'.startsWith(today)).toList();
        final cash = mine.where((d) => d['status'] == 'delivered' && d['cod_remitted_at'] == null).fold<double>(0, (t, d) => t + toDouble(d['cod_collected']));
        return Scaffold(
          appBar: standalone ? AppBar(title: Text(tr('Mes livraisons'))) : null,
          body: ListView(padding: const EdgeInsets.all(16), children: [
            if (!standalone && MediaQuery.of(context).size.width >= 1000) PageHeader(title: tr('Mes livraisons')),
            Wrap(spacing: 8, runSpacing: 8, children: [
              StatusChip(tr('${todo.length} à faire'), icon: Icons.local_shipping_outlined, color: YColors.blue),
              StatusChip(tr('${doneToday.length} livrée(s) aujourd\'hui'), icon: Icons.check_circle_outline, color: YColors.success),
              if (cash > 0) StatusChip(tr('${money(cash, cur)} à remettre'), icon: Icons.payments_outlined, color: YColors.warning),
            ]),
            const SizedBox(height: 12),
            if (todo.isNotEmpty)
              FilledButton.icon(onPressed: () => openRoute(todo), icon: const Icon(Icons.map_outlined), label: Text(tr('Itinéraire des ${todo.length} arrêts'))),
            const SizedBox(height: 8),
            if (todo.isEmpty) EmptyState(icon: Icons.task_alt, title: tr('Rien à livrer pour l\'instant'), message: tr('Vos livraisons apparaissent ici dès qu\'elles vous sont attribuées, même sans connexion.')),
            for (var i = 0; i < todo.length; i++) _DriverStop(index: i + 1, delivery: todo[i]),
            if (later.isNotEmpty) ...[
              const SizedBox(height: 16),
              Text(tr('Prochains jours (${later.length})'), style: const TextStyle(fontWeight: FontWeight.w700)),
              const SizedBox(height: 8),
              for (final d in later) DeliveryCard(delivery: d),
            ],
          ]),
        );
      },
    );
  }
}

class _DriverStop extends StatelessWidget {
  const _DriverStop({required this.index, required this.delivery});

  final int index;
  final Map<String, dynamic> delivery;

  @override
  Widget build(BuildContext context) {
    final d = delivery;
    final cur = context.read<Session>().currency;
    final status = '${d['status']}';
    final color = deliveryColors[status] ?? YColors.blue;
    final phone = '${d['recipient_phone'] ?? ''}';
    return Card(
      margin: const EdgeInsets.only(bottom: 10),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            CircleAvatar(radius: 14, backgroundColor: color, child: Text(tr('$index'), style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700))),
            const SizedBox(width: 10),
            Expanded(
              child: InkWell(
                onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => DeliveryScreen(id: d['id'] as String))),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(tr('${d['recipient_name'] ?? ''}'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
                  Text(tr('${d['number'] ?? ''}${d['time_slot'] != null ? ' · ${d['time_slot']}' : ''}'), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
                ]),
              ),
            ),
            StatusChip(deliveryStatuses[status] ?? status, color: color),
          ]),
          const SizedBox(height: 8),
          Text(tr([d['address'], d['city']].where((e) => e != null && '$e'.isNotEmpty).join(', '))),
          if ('${d['notes'] ?? ''}'.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 4), child: Text(tr('NB : ${d['notes']}'), style: const TextStyle(color: YColors.warning, fontWeight: FontWeight.w600))),
          if (toDouble(d['cod_amount']) > 0)
            Padding(padding: const EdgeInsets.only(top: 6), child: Text(tr('À encaisser : ${money(toDouble(d['cod_amount']), cur)}'), style: const TextStyle(fontWeight: FontWeight.w800, color: YColors.success))),
          const SizedBox(height: 10),
          Wrap(spacing: 8, runSpacing: 8, children: [
            if (phone.isNotEmpty) IconButton.filledTonal(tooltip: tr('Appeler'), onPressed: () => safeLaunchUrl(Uri.parse('tel:$phone')), icon: const Icon(Icons.call)),
            if (phone.isNotEmpty)
              IconButton.filledTonal(
                tooltip: tr('WhatsApp'),
                onPressed: () => safeLaunchUrl(Uri.parse('https://wa.me/${phone.replaceAll(RegExp(r'\D'), '').replaceFirst(RegExp(r'^0'), '2250')}'), mode: LaunchMode.externalApplication),
                icon: const Icon(Icons.chat_outlined),
              ),
            IconButton.filledTonal(tooltip: tr('Itinéraire'), onPressed: () => openRoute([d]), icon: const Icon(Icons.navigation_outlined)),
          ]),
          const SizedBox(height: 8),
          DeliveryActions(delivery: d),
        ]),
      ),
    );
  }
}
