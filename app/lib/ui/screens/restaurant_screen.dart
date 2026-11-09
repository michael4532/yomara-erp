import 'package:yomara_erp/ui/widgets/adaptive_sheet.dart';
import 'dart:async';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/local_db.dart';
import '../../core/sync_engine.dart';
import '../../printing/pos_printer.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/filter_bar.dart';
import 'pos_screen.dart' show posCheckout;
import '../../i18n/i18n.dart';
import '../../core/json.dart';

// ======================================================================
// Données & calculs (identiques au serveur, utilisables hors ligne)
// ======================================================================

const rsCourses = {0: 'Boissons', 1: 'Entrées', 2: 'Plats', 3: 'Desserts'};
const rsLineStatus = {'pending': 'À envoyer', 'sent': 'Envoyé', 'preparing': 'En préparation', 'ready': 'Prêt', 'served': 'Servi', 'cancelled': 'Annulé'};
const _flow = {'pending': 0, 'sent': 1, 'preparing': 2, 'ready': 3, 'served': 4};

Color rsLineColor(String? s) => switch (s) {
  'pending' => YColors.muted,
  'sent' => YColors.blue,
  'preparing' => YColors.warning,
  'ready' => YColors.success,
  'served' => YColors.muted,
  _ => YColors.danger,
};

bool rsCan(Session s) => s.hasModule('restaurant') && LocalDb.hasEntity('rs_orders');

List<Map<String, dynamic>> rsLines(String orderId) =>
    LocalDb.all('rs_order_lines').where((l) => l['order_id'] == orderId).toList()..sort((a, b) => '${a['created_at'] ?? ''}'.compareTo('${b['created_at'] ?? ''}'));

/// Quantités déjà réglées par ligne (tickets de caisse non annulés reliés à la commande).
Map<String, double> rsPaid(String orderId) {
  final paid = <String, double>{};
  for (final d in LocalDb.all('sales_documents').where((d) => d['rs_order_id'] == orderId && d['status'] != 'cancelled')) {
    for (final e in asMap(d['rs_bill']).entries) {
      paid[e.key] = (paid[e.key] ?? 0) + toDouble(e.value);
    }
  }
  return paid;
}

double rsUnitTtc(Map<String, dynamic> l) => toDouble(l['unit_price']) * (1 + toDouble(l['tax_rate']) / 100);
double rsLineTotal(Map<String, dynamic> l) => (toDouble(l['quantity']) * rsUnitTtc(l)).roundToDouble();

({double total, double paid, double left}) rsTotals(String orderId) {
  final paid = rsPaid(orderId);
  var total = 0.0, left = 0.0;
  for (final l in rsLines(orderId).where((l) => l['status'] != 'cancelled')) {
    total += rsLineTotal(l);
    left += (toDouble(l['quantity']) - (paid['${l['id']}'] ?? 0)).clamp(0, double.infinity) * rsUnitTtc(l);
  }
  return (total: total, paid: total - left.roundToDouble(), left: left.roundToDouble());
}

String rsLabel(Map<String, dynamic> o) {
  final t = o['table_id'] == null ? null : LocalDb.get('rs_tables', o['table_id'] as String);
  if (t != null) return 'Table ${t['name']}';
  return o['customer_name'] != null && '${o['customer_name']}'.isNotEmpty ? 'À emporter — ${o['customer_name']}' : 'À emporter${o['number'] != null ? ' ${o['number']}' : ''}';
}

List<Map<String, dynamic>> rsOpenOrders({String? tableId}) =>
    LocalDb.all('rs_orders').where((o) => o['status'] == 'open' && (tableId == null || o['table_id'] == tableId)).toList()
      ..sort((a, b) => '${a['opened_at'] ?? ''}'.compareTo('${b['opened_at'] ?? ''}'));

int minutesSince(dynamic iso) {
  final d = DateTime.tryParse('${iso ?? ''}');
  return d == null ? 0 : DateTime.now().toUtc().difference(d.toUtc()).inMinutes;
}

String elapsed(dynamic iso) {
  final m = minutesSince(iso);
  return m < 60 ? '$m min' : '${m ~/ 60} h ${(m % 60).toString().padLeft(2, '0')}';
}

/// État d'une table pour le plan de salle.
enum TableState { free, seated, waiting, ready, bill }

TableState tableState(String tableId) {
  final orders = rsOpenOrders(tableId: tableId);
  if (orders.isEmpty) return TableState.free;
  if (orders.any((o) => o['bill_requested_at'] != null)) return TableState.bill;
  final lines = [for (final o in orders) ...rsLines('${o['id']}')];
  if (lines.any((l) => l['status'] == 'ready')) return TableState.ready;
  if (lines.any((l) => l['status'] == 'sent' || l['status'] == 'preparing')) return TableState.waiting;
  return TableState.seated;
}

const tableStateLabel = {TableState.free: 'Libre', TableState.seated: 'Installée', TableState.waiting: 'En attente cuisine', TableState.ready: 'Plats prêts', TableState.bill: 'Addition demandée'};
const tableStateColor = {TableState.free: YColors.success, TableState.seated: YColors.blue, TableState.waiting: YColors.warning, TableState.ready: Color(0xFF7A4FD6), TableState.bill: YColors.danger};

Map<String, dynamic>? _register() {
  final regs = LocalDb.all('pos_registers').where((r) => r['active'] != false).toList();
  if (regs.isEmpty) return null;
  final id = LocalDb.meta.get('pos_register_id') as String?;
  return regs.firstWhere((r) => r['id'] == id, orElse: () => regs.first);
}

Map<String, dynamic>? _openPosSession(String registerId) {
  final open = LocalDb.all('pos_sessions').where((s) => s['register_id'] == registerId && s['status'] == 'open').toList()..sort((a, b) => '${b['opened_at']}'.compareTo('${a['opened_at']}'));
  return open.isEmpty ? null : open.first;
}

bool get printKitchenTickets => LocalDb.meta.get('rs_print_tickets') == true;

/// Ouvre une commande depuis une notification ou un lien.
void openRestaurantOrder(BuildContext context, String id) {
  if (LocalDb.get('rs_orders', id) == null) return;
  Navigator.of(context).push(MaterialPageRoute(builder: (_) => OrderScreen(id: id)));
}

/// Nouvelle commande : couverts (sur place) ou nom du client (à emporter).
Future<String?> newOrder(BuildContext context, {Map<String, dynamic>? table}) async {
  final sync = context.read<SyncEngine>();
  final s = context.read<Session>();
  var guests = toDouble(table?['seats'] ?? 2).round().clamp(1, 12);
  final name = TextEditingController();
  final ok = await showDialog<bool>(
    context: context,
    builder: (c) => StatefulBuilder(
      builder: (c, set) => YAlertDialog(
        title: Text(tr(table != null ? 'Table ${table['name']}' : 'Commande à emporter')),
        content: SizedBox(
          width: 360,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if (table != null) ...[
                Text(tr('Nombre de couverts')),
                const SizedBox(height: 8),
                Wrap(
                  spacing: 6,
                  runSpacing: 6,
                  children: [for (var n = 1; n <= 12; n++) ChoiceChip(label: Text(tr('$n')), selected: guests == n, onSelected: (_) => set(() => guests = n))],
                ),
              ] else
                TextField(
                  controller: name,
                  autofocus: true,
                  decoration: InputDecoration(labelText: tr('Nom du client (facultatif)')),
                  onSubmitted: (_) => Navigator.pop(c, true),
                ),
            ],
          ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))),
          FilledButton(autofocus: table != null, onPressed: () => Navigator.pop(c, true), child: Text(tr('Ouvrir la commande'))),
        ],
      ),
    ),
  );
  if (ok != true) return null;
  final o = await sync.save('rs_orders', {
    'id': newId(),
    'table_id': table?['id'],
    'kind': table == null ? 'takeaway' : 'dine_in',
    'guests': table == null ? 1 : guests,
    if (name.text.trim().isNotEmpty) 'customer_name': name.text.trim(),
    'status': 'open',
    'opened_at': DateTime.now().toUtc().toIso8601String(),
    'waiter_id': s.user['id'],
    'waiter_name': s.user['name'],
  });
  return '${o['id']}';
}

// ======================================================================
// Écran principal
// ======================================================================

class RestaurantScreen extends StatefulWidget {
  const RestaurantScreen({super.key});

  @override
  State<RestaurantScreen> createState() => _RestaurantScreenState();
}

class _RestaurantScreenState extends State<RestaurantScreen> with SingleTickerProviderStateMixin {
  late final Session _s = context.read<Session>();
  late final bool _serve = _s.can('restaurant.serve') || _s.can('restaurant.manage');
  late final bool _kitchen = _s.can('restaurant.kitchen') || _s.can('restaurant.manage');
  late final bool _manage = _s.can('restaurant.manage');
  late final List<(String, Widget)> _tabs = [
    if (_serve) ('Salle', const _FloorTab()),
    if (_serve) ('À emporter', const _TakeawayTab()),
    if (_kitchen) ('Cuisine & bar', const KitchenView()),
    if (_manage) ('Réglages', const _SettingsTab()),
    if (_manage) ('Statistiques', const _StatsTab()),
  ];
  late final TabController _tc = TabController(length: _tabs.length, vsync: this);

  @override
  Widget build(BuildContext context) {
    if (_tabs.isEmpty) return EmptyState(icon: Icons.lock_outline, title: tr('Accès non autorisé'), message: tr('Demandez à votre responsable le rôle Serveur ou Cuisine / bar.'));
    return Column(
      children: [
        PageHeader(title: tr('Restaurant & bar'), subtitle: tr('Plan de salle, commandes, cuisine et additions')),
        if (_tabs.length > 1)
          TabBar(
            controller: _tc,
            isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8),
            tabAlignment: TabAlignment.start,
            tabs: [for (final t in _tabs) Tab(text: trn(t.$1))],
          ),
        Expanded(
          child: TabBarView(controller: _tc, physics: const NeverScrollableScrollPhysics(), children: [for (final t in _tabs) t.$2]),
        ),
      ],
    );
  }
}

// ======================================================================
// Plan de salle
// ======================================================================

class _FloorTab extends StatefulWidget {
  const _FloorTab();

  @override
  State<_FloorTab> createState() => _FloorTabState();
}

class _FloorTabState extends State<_FloorTab> {
  String? _floor = LocalDb.meta.get('rs_floor') as String?;
  bool _edit = false;
  Timer? _tick;
  final Map<String, Offset> _drag = {};

  @override
  void initState() {
    super.initState();
    _tick = Timer.periodic(const Duration(seconds: 30), (_) => mounted ? setState(() {}) : null);
  }

  @override
  void dispose() {
    _tick?.cancel();
    super.dispose();
  }

  Future<void> _openTable(Map<String, dynamic> t) async {
    final orders = rsOpenOrders(tableId: '${t['id']}');
    String? id;
    if (orders.isEmpty) {
      id = await newOrder(context, table: t);
    } else if (orders.length == 1) {
      id = '${orders.first['id']}';
    } else {
      id = await showDialog<String>(
        context: context,
        builder: (c) => SimpleDialog(
          title: Text(tr('Table ${t['name']}')),
          children: [
            for (final o in orders) SimpleDialogOption(onPressed: () => Navigator.pop(c, '${o['id']}'), child: Text(tr('${o['number'] ?? 'Commande'} · ${o['guests']} couv. · ${o['waiter_name'] ?? ''}'))),
            SimpleDialogOption(
              onPressed: () async {
                Navigator.pop(c);
                final n = await newOrder(context, table: t);
                if (n != null && mounted) openRestaurantOrder(context, n);
              },
              child: Text(tr('+ Nouvelle commande sur cette table')),
            ),
          ],
        ),
      );
    }
    if (id != null && mounted) openRestaurantOrder(context, id);
  }

  @override
  Widget build(BuildContext context) {
    final manage = context.read<Session>().can('restaurant.manage');
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('rs_tables'),
      builder: (context, _, _) => ValueListenableBuilder(
        valueListenable: LocalDb.listen('rs_orders'),
        builder: (context, _, _) => ValueListenableBuilder(
          valueListenable: LocalDb.listen('rs_order_lines'),
          builder: (context, _, _) {
            final floors = LocalDb.all('rs_floors')..sort((a, b) => ((a['sort'] as num?) ?? 0).compareTo((b['sort'] as num?) ?? 0));
            final floor = floors.any((f) => f['id'] == _floor) ? _floor : (floors.isEmpty ? null : '${floors.first['id']}');
            final tables = LocalDb.all('rs_tables').where((t) => t['active'] != false && (floors.isEmpty || t['floor_id'] == floor || (t['floor_id'] == null && floor == floors.first['id']))).toList();
            final busy = LocalDb.all('rs_tables').where((t) => tableState('${t['id']}') != TableState.free).length;
            return Column(
              children: [
                Padding(
                  padding: const EdgeInsets.fromLTRB(16, 10, 16, 6),
                  child: Row(
                    children: [
                      Expanded(
                        child: SingleChildScrollView(
                          scrollDirection: Axis.horizontal,
                          child: Row(
                            children: [
                              for (final f in floors)
                                Padding(
                                  padding: const EdgeInsets.only(right: 8),
                                  child: ChoiceChip(
                                    label: Text(tr('${f['name']}')),
                                    selected: floor == f['id'],
                                    onSelected: (_) {
                                      LocalDb.meta.put('rs_floor', f['id']);
                                      setState(() => _floor = '${f['id']}');
                                    },
                                  ),
                                ),
                              Text(tr('$busy table(s) occupée(s)'), style: TextStyle(color: Theme.of(context).hintColor)),
                            ],
                          ),
                        ),
                      ),
                      if (manage && _edit)
                        TextButton.icon(
                          onPressed: () => editTable(context, floorId: floor),
                          icon: const Icon(Icons.add),
                          label: Text(tr('Table')),
                        ),
                      if (manage) IconButton(tooltip: trn(_edit ? 'Terminer' : 'Modifier le plan'), onPressed: () => setState(() => _edit = !_edit), icon: Icon(_edit ? Icons.check : Icons.edit_outlined)),
                    ],
                  ),
                ),
                if (_edit)
                  Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 16),
                    child: Text(tr('Glissez les tables pour les placer, touchez-en une pour la modifier.'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12)),
                  ),
                Expanded(
                  child: tables.isEmpty
                      ? EmptyState(
                          icon: Icons.table_restaurant_outlined,
                          title: tr('Aucune table'),
                          message: manage ? 'Touchez le crayon puis « + Table » pour dessiner votre salle.' : 'Votre responsable doit d\'abord dessiner le plan de salle.',
                        )
                      : LayoutBuilder(
                          builder: (context, box) {
                            // Téléphone : grille de tuiles plutôt que le plan (plus lisible au doigt)
                            if (box.maxWidth < 600 && !_edit) {
                              final sorted = [...tables]..sort((a, b) => (int.tryParse('${a['name']}') ?? 999).compareTo(int.tryParse('${b['name']}') ?? 999));
                              return GridView.builder(
                                padding: const EdgeInsets.all(12),
                                gridDelegate: const SliverGridDelegateWithMaxCrossAxisExtent(maxCrossAxisExtent: 120, mainAxisExtent: 96, crossAxisSpacing: 8, mainAxisSpacing: 8),
                                itemCount: sorted.length,
                                itemBuilder: (_, i) => _table(sorted[i], 0, tile: true),
                              );
                            }
                            final cell = ((box.maxWidth - 24) / 24 < (box.maxHeight - 24) / 16 ? (box.maxWidth - 24) / 24 : (box.maxHeight - 24) / 16).clamp(26.0, 64.0).toDouble();
                            final canvas = Size(cell * 24, cell * 16);
                            return SingleChildScrollView(
                              padding: const EdgeInsets.all(12),
                              child: SingleChildScrollView(
                                scrollDirection: Axis.horizontal,
                                child: Container(
                                  width: canvas.width,
                                  height: canvas.height,
                                  decoration: BoxDecoration(
                                    color: Theme.of(context).colorScheme.surface,
                                    borderRadius: BorderRadius.circular(16),
                                    border: Border.all(color: _edit ? YColors.blue : Theme.of(context).dividerColor),
                                  ),
                                  child: CustomPaint(
                                    painter: _edit ? _GridPainter(cell) : null,
                                    child: Stack(children: [for (final t in tables) _table(t, cell)]),
                                  ),
                                ),
                              ),
                            );
                          },
                        ),
                ),
                Padding(
                  padding: const EdgeInsets.fromLTRB(16, 0, 16, 10),
                  child: Wrap(
                    spacing: 12,
                    runSpacing: 4,
                    children: [
                      for (final e in tableStateLabel.entries)
                        Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Container(
                              width: 10,
                              height: 10,
                              decoration: BoxDecoration(color: tableStateColor[e.key], shape: BoxShape.circle),
                            ),
                            const SizedBox(width: 4),
                            Text(tr(e.value), style: const TextStyle(fontSize: 12)),
                          ],
                        ),
                    ],
                  ),
                ),
              ],
            );
          },
        ),
      ),
    );
  }

  Widget _table(Map<String, dynamic> t, double cell, {bool tile = false}) {
    final id = '${t['id']}';
    final st = tableState(id);
    final color = tableStateColor[st]!;
    final orders = rsOpenOrders(tableId: id);
    final w = ((t['w'] as num?) ?? 2).toDouble() * cell;
    final h = ((t['h'] as num?) ?? 2).toDouble() * cell;
    final drag = _drag[id] ?? Offset.zero;
    final x = ((t['x'] as num?) ?? 0).toDouble() * cell + drag.dx;
    final y = ((t['y'] as num?) ?? 0).toDouble() * cell + drag.dy;
    final round = t['shape'] == 'round';
    final total = orders.fold<double>(0, (s, o) => s + rsTotals('${o['id']}').total);
    final small = !tile && (w < 90 || h < 70);
    final box = GestureDetector(
      onTap: () => _edit ? editTable(context, table: t) : _openTable(t),
      onPanUpdate: _edit ? (d) => setState(() => _drag[id] = drag + d.delta) : null,
      onPanEnd: _edit
          ? (_) async {
              final nx = (x / cell).round().clamp(0, 24 - ((t['w'] as num?) ?? 2).toInt());
              final ny = (y / cell).round().clamp(0, 16 - ((t['h'] as num?) ?? 2).toInt());
              setState(() => _drag.remove(id));
              await LocalDb.put('rs_tables', {...t, 'x': nx, 'y': ny});
              if (mounted) await context.read<SyncEngine>().save('rs_tables', {'id': id, 'x': nx, 'y': ny});
            }
          : null,
      child: Container(
        margin: EdgeInsets.all(tile ? 0 : cell * 0.12),
        decoration: BoxDecoration(
          color: color.withValues(alpha: st == TableState.free ? 0.10 : 0.18),
          border: Border.all(color: color, width: st == TableState.free ? 1.5 : 2.5),
          borderRadius: round ? BorderRadius.circular(200) : BorderRadius.circular(12),
        ),
        child: Center(
          child: FittedBox(
            fit: BoxFit.scaleDown,
            child: Padding(
              padding: const EdgeInsets.all(4),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    tr('${t['name']}'),
                    style: TextStyle(fontWeight: FontWeight.w800, fontSize: small ? 14 : 20, color: st == TableState.free ? null : color),
                  ),
                  if (orders.isEmpty)
                    Text(tr('${t['seats'] ?? ''} pl.'), style: TextStyle(fontSize: 11, color: Theme.of(context).hintColor))
                  else ...[
                    // Petite table : couverts et durée sur deux lignes courtes (sinon le texte est réduit au point d'être illisible)
                    if (small || w < 90) ...[
                      Text(tr('${orders.fold<int>(0, (s, o) => s + ((o['guests'] as num?) ?? 0).toInt())} couv.'), style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w600)),
                      Text(tr(elapsed(orders.first['opened_at'])), style: const TextStyle(fontSize: 10.5)),
                    ] else
                      Text(tr('${orders.fold<int>(0, (s, o) => s + ((o['guests'] as num?) ?? 0).toInt())} couv. · ${elapsed(orders.first['opened_at'])}'), style: const TextStyle(fontSize: 11)),
                    if (!small && total > 0) Text(tr(money(total, context.read<Session>().currency)), style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w700)),
                  ],
                ],
              ),
            ),
          ),
        ),
      ),
    );
    return tile ? box : Positioned(left: x, top: y, width: w, height: h, child: box);
  }
}

class _GridPainter extends CustomPainter {
  _GridPainter(this.cell);

  final double cell;

  @override
  void paint(Canvas canvas, Size size) {
    final p = Paint()
      ..color = YColors.line
      ..strokeWidth = 1;
    for (var i = 1; i < 24; i++) {
      canvas.drawLine(Offset(i * cell, 0), Offset(i * cell, size.height), p);
    }
    for (var j = 1; j < 16; j++) {
      canvas.drawLine(Offset(0, j * cell), Offset(size.width, j * cell), p);
    }
  }

  @override
  bool shouldRepaint(covariant _GridPainter old) => old.cell != cell;
}

Future<void> editTable(BuildContext context, {Map<String, dynamic>? table, String? floorId}) async {
  final sync = context.read<SyncEngine>();
  final floors = LocalDb.all('rs_floors')..sort((a, b) => ((a['sort'] as num?) ?? 0).compareTo((b['sort'] as num?) ?? 0));
  final name = TextEditingController(text: '${table?['name'] ?? (LocalDb.all('rs_tables').length + 1)}');
  var seats = ((table?['seats'] as num?) ?? 4).toInt();
  var shape = '${table?['shape'] ?? 'square'}';
  var size = '${table?['w'] ?? 2}x${table?['h'] ?? 2}';
  String? floor = (table?['floor_id'] as String?) ?? floorId;
  final ok = await showDialog<String>(
    context: context,
    builder: (c) => StatefulBuilder(
      builder: (c, set) => YAlertDialog(
        title: Text(tr(table == null ? 'Nouvelle table' : 'Table ${table['name']}')),
        content: SizedBox(
          width: 380,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              TextField(
                controller: name,
                decoration: InputDecoration(labelText: tr('Nom ou numéro')),
              ),
              const SizedBox(height: 12),
              Row(
                children: [
                  Text(tr('Places')),
                  IconButton(onPressed: seats > 1 ? () => set(() => seats--) : null, icon: const Icon(Icons.remove_circle_outline)),
                  Text(tr('$seats'), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
                  IconButton(onPressed: () => set(() => seats++), icon: const Icon(Icons.add_circle_outline)),
                ],
              ),
              SegmentedButton<String>(
                segments: [
                  ButtonSegment(value: 'square', label: Text(tr('Carrée'))),
                  ButtonSegment(value: 'round', label: Text(tr('Ronde'))),
                  ButtonSegment(value: 'rect', label: Text(tr('Longue'))),
                ],
                selected: {shape},
                onSelectionChanged: (v) => set(() {
                  shape = v.first;
                  if (shape == 'rect' && size == '2x2') size = '4x2';
                }),
              ),
              const SizedBox(height: 8),
              Wrap(
                spacing: 6,
                children: [
                  for (final z in ['2x2', '3x3', '4x2', '6x2', '2x4']) ChoiceChip(label: Text(tr(z.replaceAll('x', ' × '))), selected: size == z, onSelected: (_) => set(() => size = z)),
                ],
              ),
              if (floors.length > 1)
                DropdownButtonFormField<String?>(
                  initialValue: floors.any((f) => f['id'] == floor) ? floor : null,
                  decoration: InputDecoration(labelText: tr('Salle')),
                  items: [for (final f in floors) DropdownMenuItem<String?>(value: '${f['id']}', child: Text(tr('${f['name']}')))],
                  onChanged: (v) => set(() => floor = v),
                ),
            ],
          ),
        ),
        actions: [
          if (table != null)
            TextButton(
              onPressed: () => Navigator.pop(c, 'delete'),
              child: Text(tr('Supprimer'), style: TextStyle(color: YColors.danger)),
            ),
          TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))),
          FilledButton(onPressed: () => Navigator.pop(c, 'save'), child: Text(tr('Enregistrer'))),
        ],
      ),
    ),
  );
  try {
    if (ok == 'delete') {
      if (rsOpenOrders(tableId: '${table!['id']}').isNotEmpty) {
        if (context.mounted) toast(context, tr('Cette table a une commande en cours.'), error: true);
        return;
      }
      await sync.remove('rs_tables', '${table['id']}');
    }
    if (ok != 'save' || name.text.trim().isEmpty) return;
    final wh = size.split('x').map(int.parse).toList();
    // Nouvelle table : première place libre du plan
    var x = ((table?['x'] as num?) ?? 0).toInt(), y = ((table?['y'] as num?) ?? 0).toInt();
    if (table == null) {
      final others = LocalDb.all('rs_tables').where((t) => t['floor_id'] == floor).toList();
      bool free(int cx, int cy) => !others.any((t) {
        final tx = ((t['x'] as num?) ?? 0).toInt(), ty = ((t['y'] as num?) ?? 0).toInt();
        final tw = ((t['w'] as num?) ?? 2).toInt(), th = ((t['h'] as num?) ?? 2).toInt();
        return cx < tx + tw && cx + wh[0] > tx && cy < ty + th && cy + wh[1] > ty;
      });
      outer:
      for (var cy = 1; cy + wh[1] <= 16; cy += 1) {
        for (var cx = 1; cx + wh[0] <= 24; cx += 1) {
          if (free(cx, cy)) {
            x = cx;
            y = cy;
            break outer;
          }
        }
      }
    }
    await sync.save('rs_tables', {
      'id': table?['id'] ?? newId(),
      'name': name.text.trim(),
      'seats': seats,
      'shape': shape,
      'w': wh[0],
      'h': wh[1],
      'x': x.clamp(0, 24 - wh[0]),
      'y': y.clamp(0, 16 - wh[1]),
      'floor_id': floor,
      'active': true,
    });
  } catch (e) {
    if (context.mounted) toast(context, errorText(e), error: true);
  }
}

// ======================================================================
// À emporter
// ======================================================================

class _TakeawayTab extends StatefulWidget {
  const _TakeawayTab();

  @override
  State<_TakeawayTab> createState() => _TakeawayTabState();
}

class _TakeawayTabState extends State<_TakeawayTab> {
  String _q = '';
  int _rk = 0;
  String _status = '';
  Map<String, String?> _f = {};

  bool _ready(Map<String, dynamic> o) {
    final lines = rsLines('${o['id']}').where((l) => l['status'] != 'cancelled').toList();
    return lines.isNotEmpty && lines.every((l) => (_flow[l['status']] ?? 0) >= 3);
  }

  @override
  Widget build(BuildContext context) {
    final cur = context.read<Session>().currency;
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('rs_orders'),
      builder: (context, _, _) => ValueListenableBuilder(
        valueListenable: LocalDb.listen('rs_order_lines'),
        builder: (context, _, _) {
          final all = rsOpenOrders().where((o) => o['table_id'] == null).toList();
          final q = _q.toLowerCase();
          final base = all
              .where((o) => q.isEmpty || '${o['number'] ?? ''} ${o['customer_name'] ?? ''} ${o['waiter_name'] ?? ''}'.toLowerCase().contains(q))
              .where((o) => _f['waiter'] == null || '${o['waiter_name'] ?? ''}' == _f['waiter'])
              .where((o) => _f['bill'] == null || (o['bill_requested_at'] != null) == (_f['bill'] == 'yes'))
              .toList();
          bool st(Map<String, dynamic> o, String k) => k.isEmpty || (k == 'ready' ? _ready(o) : !_ready(o));
          final orders = base.where((o) => st(o, _status)).toList();
          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              Align(
                alignment: Alignment.centerLeft,
                child: FilledButton.icon(
                  onPressed: () async {
                    final id = await newOrder(context);
                    if (id != null && context.mounted) openRestaurantOrder(context, id);
                  },
                  icon: const Icon(Icons.takeout_dining_outlined),
                  label: Text(tr('Nouvelle commande à emporter')),
                ),
              ),
              const SizedBox(height: 12),
              if (all.isNotEmpty)
                FilterBar(
                  key: ValueKey(_rk),
                  padding: const EdgeInsets.only(bottom: 12),
                  onSearch: (v) => setState(() => _q = v),
                  search: _q,
                  searchHint: 'N°, client, serveur…',
                  statuses: const {'': 'Toutes', 'preparing': 'En préparation', 'ready': 'Prêtes'},
                  status: _status,
                  onStatus: (v) => setState(() => _status = v),
                  statusCounts: {for (final k in ['', 'preparing', 'ready']) k: base.where((o) => st(o, k)).length},
                  filters: [
                    FilterSpec('waiter', 'Serveur', filterOptions(all, 'waiter_name'), icon: Icons.person_outline),
                    const FilterSpec('bill', 'Addition', {'yes': 'Addition demandée', 'no': 'Pas encore demandée'}, icon: Icons.receipt_long_outlined),
                  ],
                  values: _f,
                  onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
                  count: orders.length,
                  countLabel: 'commande',
                  onReset: () => setState(() => (_rk++, _q = '', _status = '', _f = {})),
                ),
              if (all.isEmpty)
                EmptyState(icon: Icons.takeout_dining_outlined, title: tr('Aucune commande à emporter en cours'), message: tr('Les commandes à emporter passent en cuisine comme celles de la salle.')),
              if (all.isNotEmpty && orders.isEmpty) EmptyState(icon: Icons.filter_alt_off_outlined, title: tr('Aucune commande ne correspond aux filtres')),
              for (final o in orders)
                Builder(
                  builder: (context) {
                    final lines = rsLines('${o['id']}').where((l) => l['status'] != 'cancelled').toList();
                    final ready = lines.isNotEmpty && lines.every((l) => (_flow[l['status']] ?? 0) >= 3);
                    return Card(
                      child: ListTile(
                        leading: Icon(ready ? Icons.check_circle : Icons.takeout_dining, color: ready ? YColors.success : YColors.blue),
                        title: Text(tr(rsLabel(o))),
                        subtitle: Text(tr('${lines.length} article(s) · ${elapsed(o['opened_at'])}${ready ? ' · prête' : ''}')),
                        trailing: Text(tr(money(rsTotals('${o['id']}').total, cur)), style: const TextStyle(fontWeight: FontWeight.w700)),
                        onTap: () => openRestaurantOrder(context, '${o['id']}'),
                      ),
                    );
                  },
                ),
            ],
          );
        },
      ),
    );
  }
}

// ======================================================================
// Commande : carte + ticket
// ======================================================================

class OrderScreen extends StatefulWidget {
  const OrderScreen({super.key, required this.id});

  final String id;

  @override
  State<OrderScreen> createState() => _OrderScreenState();
}

class _OrderScreenState extends State<OrderScreen> {
  String? _category;
  final _search = TextEditingController();
  int _course = -1; // -1 : suite proposée selon le poste du produit

  Map<String, dynamic>? get _order => LocalDb.get('rs_orders', widget.id);

  int _defaultCourse(Map<String, dynamic> p) {
    if (_course >= 0) return _course;
    final st = LocalDb.all('rs_stations').where((s) => ((s['category_ids'] as List?) ?? []).contains(p['category_id'])).firstOrNull;
    if (st?['kind'] == 'bar') return 0;
    final cat = '${LocalDb.get('product_categories', p['category_id'] as String?)?['name'] ?? ''}'.toLowerCase();
    if (cat.contains('dessert') || cat.contains('glace') || cat.contains('pâtisserie')) return 3;
    if (cat.contains('entrée') || cat.contains('entree') || cat.contains('apéritif') || cat.contains('tapas')) return 1;
    if (cat.contains('boisson') || cat.contains('bar') || cat.contains('cocktail') || cat.contains('vin')) return 0;
    return 2;
  }

  Future<void> _add(Map<String, dynamic> p, {double q = 1, String? note, int? course}) async {
    final o = _order;
    if (o == null || o['status'] != 'open') return;
    final c = course ?? _defaultCourse(p);
    final sync = context.read<SyncEngine>();
    final same = rsLines(widget.id).where((l) => l['status'] == 'pending' && l['product_id'] == p['id'] && (l['note'] ?? '') == (note ?? '') && l['course'] == c).firstOrNull;
    if (same != null) {
      await sync.save('rs_order_lines', {'id': same['id'], 'quantity': toDouble(same['quantity']) + q});
      return;
    }
    await sync.save('rs_order_lines', {
      'id': newId(),
      'order_id': widget.id,
      'product_id': p['id'],
      'description': p['name'],
      'quantity': q,
      'unit_price': toDouble(p['sale_price']),
      'tax_rate': toDouble(p['tax_rate']),
      'course': c,
      if (note != null && note.isNotEmpty) 'note': note,
      'status': 'pending',
      'created_at': DateTime.now().toUtc().toIso8601String(),
    });
  }

  Future<void> _addDetailed(Map<String, dynamic> p) async {
    var q = 1.0;
    var course = _defaultCourse(p);
    final note = TextEditingController();
    final presets = ['Saignant', 'À point', 'Bien cuit', 'Sans piment', 'Piment à part', 'Sans oignon', 'Bien frais', 'Sans glaçons'];
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => StatefulBuilder(
        builder: (c, set) => YAlertDialog(
          title: Text(tr('${p['name']}')),
          content: SizedBox(
            width: 400,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    IconButton(onPressed: q > 1 ? () => set(() => q--) : null, icon: const Icon(Icons.remove_circle_outline)),
                    Text(tr(qty(q)), style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800)),
                    IconButton(onPressed: () => set(() => q++), icon: const Icon(Icons.add_circle_outline)),
                  ],
                ),
                const SizedBox(height: 8),
                Wrap(
                  spacing: 6,
                  children: [for (final e in rsCourses.entries) ChoiceChip(label: Text(tr(e.value)), selected: course == e.key, onSelected: (_) => set(() => course = e.key))],
                ),
                const SizedBox(height: 8),
                TextField(
                  controller: note,
                  decoration: InputDecoration(labelText: tr('Précision pour la cuisine')),
                ),
                const SizedBox(height: 8),
                Wrap(
                  spacing: 6,
                  runSpacing: 6,
                  children: [for (final pr in presets) ActionChip(label: Text(tr(pr)), onPressed: () => set(() => note.text = note.text.isEmpty ? pr : '${note.text}, $pr'))],
                ),
              ],
            ),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))),
            FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Ajouter'))),
          ],
        ),
      ),
    );
    if (ok == true) await _add(p, q: q, note: note.text.trim(), course: course);
  }

  /// Envoi en cours : un double appui sur « Envoyer » n'imprime pas deux fois les bons de cuisine.
  bool _sending = false;

  Future<void> _send({Set<int>? courses}) async {
    if (_sending) return;
    final sync = context.read<SyncEngine>();
    final pending = rsLines(widget.id).where((l) => l['status'] == 'pending' && (courses == null || courses.contains(l['course']))).toList();
    if (pending.isEmpty) return;
    _sending = true;
    try {
      final now = DateTime.now().toUtc().toIso8601String();
      for (final l in pending) {
        await sync.save('rs_order_lines', {'id': l['id'], 'status': 'sent', 'sent_at': now});
      }
      if (mounted) toast(context, tr('${pending.length} ligne(s) envoyée(s) en cuisine et au bar.'));
      if (printKitchenTickets) await _printTickets(pending);
    } finally {
      _sending = false;
    }
  }

  Future<void> _printTickets(List<Map<String, dynamic>> lines) async {
    final o = _order!;
    final stations = {for (final s in LocalDb.all('rs_stations')) '${s['id']}': s};
    final byStation = <String, List<Map<String, dynamic>>>{};
    for (final l in lines) {
      byStation.putIfAbsent('${l['station_id'] ?? _guessStation(l)}', () => []).add(l);
    }
    for (final e in byStation.entries) {
      final sorted = e.value..sort((a, b) => ((a['course'] as num?) ?? 0).compareTo((b['course'] as num?) ?? 0));
      final warn = await PosPrinter.printKitchenTicket(
        station: '${stations[e.key]?['name'] ?? 'Cuisine'}',
        title: rsLabel(o),
        subtitle: tr('${o['guests'] ?? 1} couv. · ${o['waiter_name'] ?? ''}'),
        lines: sorted,
      );
      if (warn != null && mounted) toast(context, warn, error: true);
    }
  }

  String? _guessStation(Map<String, dynamic> l) {
    final p = LocalDb.get('products', l['product_id'] as String?);
    final stations = LocalDb.all('rs_stations')..sort((a, b) => ((a['sort'] as num?) ?? 0).compareTo((b['sort'] as num?) ?? 0));
    return (stations.where((s) => ((s['category_ids'] as List?) ?? []).contains(p?['category_id'])).firstOrNull ?? stations.where((s) => s['kind'] == 'kitchen').firstOrNull)?['id'] as String?;
  }

  Future<void> _lineMenu(Map<String, dynamic> l) async {
    final sync = context.read<SyncEngine>();
    final s = context.read<Session>();
    final st = '${l['status']}';
    final paid = rsPaid(widget.id)['${l['id']}'] ?? 0;
    final choice = await showAdaptiveSheet<String>(
      context: context,
      builder: (c) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            ListTile(
              title: Text(tr('${qty(toDouble(l['quantity']))} × ${l['description']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
              subtitle: Text(tr(rsLineStatus[st] ?? st)),
            ),
            if (st == 'pending') ...[
              ListTile(leading: const Icon(Icons.edit_note), title: Text(tr('Précision, suite')), onTap: () => Navigator.pop(c, 'edit')),
              ListTile(
                leading: const Icon(Icons.delete_outline, color: YColors.danger),
                title: Text(tr('Retirer')),
                onTap: () => Navigator.pop(c, 'delete'),
              ),
            ],
            if (st == 'ready' || st == 'sent' || st == 'preparing')
              ListTile(leading: const Icon(Icons.room_service_outlined), title: Text(tr('Marquer servi')), onTap: () => Navigator.pop(c, 'served')),
            if (st != 'pending' && st != 'cancelled' && paid <= 0 && (st != 'served' || s.can('restaurant.manage')))
              ListTile(
                leading: const Icon(Icons.block, color: YColors.danger),
                title: Text(tr('Annuler (avec motif)')),
                onTap: () => Navigator.pop(c, 'cancel'),
              ),
          ],
        ),
      ),
    );
    try {
      switch (choice) {
        case 'delete':
          await sync.remove('rs_order_lines', '${l['id']}');
        case 'served':
          await sync.save('rs_order_lines', {'id': l['id'], 'status': 'served'});
        case 'cancel':
          if (!mounted) return;
          final reason = await _askReason(context, 'Annuler ${l['description']}');
          if (reason != null) await sync.save('rs_order_lines', {'id': l['id'], 'status': 'cancelled', 'cancel_reason': reason});
        case 'edit':
          final note = TextEditingController(text: '${l['note'] ?? ''}');
          var course = ((l['course'] as num?) ?? 2).toInt();
          if (!mounted) return;
          final ok = await showDialog<bool>(
            context: context,
            builder: (c) => StatefulBuilder(
              builder: (c, set) => YAlertDialog(
                title: Text(tr('${l['description']}')),
                content: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Wrap(
                      spacing: 6,
                      children: [for (final e in rsCourses.entries) ChoiceChip(label: Text(tr(e.value)), selected: course == e.key, onSelected: (_) => set(() => course = e.key))],
                    ),
                    TextField(
                      controller: note,
                      decoration: InputDecoration(labelText: tr('Précision pour la cuisine')),
                    ),
                  ],
                ),
                actions: [
                  TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))),
                  FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Enregistrer'))),
                ],
              ),
            ),
          );
          if (ok == true) await sync.save('rs_order_lines', {'id': l['id'], 'note': note.text.trim().isEmpty ? null : note.text.trim(), 'course': course});
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _orderMenu(String v) async {
    final sync = context.read<SyncEngine>();
    final o = _order!;
    try {
      switch (v) {
        case 'bill':
          await sync.save('rs_orders', {'id': widget.id, 'bill_requested_at': o['bill_requested_at'] == null ? DateTime.now().toUtc().toIso8601String() : null});
        case 'guests':
          var g = ((o['guests'] as num?) ?? 1).toInt();
          final ok = await showDialog<bool>(
            context: context,
            builder: (c) => StatefulBuilder(
              builder: (c, set) => YAlertDialog(
                title: Text(tr('Couverts')),
                content: Wrap(
                  spacing: 6,
                  runSpacing: 6,
                  children: [for (var n = 1; n <= 20; n++) ChoiceChip(label: Text(tr('$n')), selected: g == n, onSelected: (_) => set(() => g = n))],
                ),
                actions: [FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('OK')))],
              ),
            ),
          );
          if (ok == true) await sync.save('rs_orders', {'id': widget.id, 'guests': g});
        case 'move':
          final tables = LocalDb.all('rs_tables').where((t) => t['active'] != false && t['id'] != o['table_id']).toList()..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));
          final t = await showDialog<Map<String, dynamic>>(
            context: context,
            builder: (c) => SimpleDialog(
              title: Text(tr('Transférer vers la table')),
              children: [
                for (final t in tables) SimpleDialogOption(onPressed: () => Navigator.pop(c, t), child: Text(tr('Table ${t['name']}${tableState('${t['id']}') != TableState.free ? ' (occupée)' : ''}'))),
              ],
            ),
          );
          if (t != null) await sync.save('rs_orders', {'id': widget.id, 'table_id': t['id'], 'kind': 'dine_in'});
        case 'print':
          await _printNote();
        case 'tickets':
          await _printTickets(rsLines(widget.id).where((l) => l['status'] == 'sent' || l['status'] == 'preparing').toList());
        case 'cancel':
          final sent = rsLines(widget.id).any((l) => l['status'] != 'pending' && l['status'] != 'cancelled');
          String? reason;
          if (sent) {
            reason = await _askReason(context, 'Annuler la commande');
            if (reason == null) return;
          } else if (!mounted || !await confirm(context, tr('Annuler la commande'), tr('La table sera libérée.'), ok: tr('Annuler la commande'), danger: true)) {
            return;
          }
          await sync.save('rs_orders', {'id': widget.id, 'status': 'cancelled', 'cancel_reason': reason});
          if (mounted) Navigator.pop(context);
        case 'free':
          await sync.save('rs_orders', {'id': widget.id, 'status': 'closed'});
          if (mounted) Navigator.pop(context);
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  /// Note (addition avant paiement) imprimée pour la table.
  Future<void> _printNote() async {
    final s = context.read<Session>();
    final o = _order!;
    final paid = rsPaid(widget.id);
    final lines = [
      for (final l in rsLines(widget.id).where((l) => l['status'] != 'cancelled'))
        if (toDouble(l['quantity']) - (paid['${l['id']}'] ?? 0) > 0)
          {...l, 'quantity': toDouble(l['quantity']) - (paid['${l['id']}'] ?? 0), 'discount_percent': 0, 'total': (toDouble(l['quantity']) - (paid['${l['id']}'] ?? 0)) * rsUnitTtc(l)},
    ];
    final t = rsTotals(widget.id);
    final sub = lines.fold<double>(0, (a, l) => a + toDouble(l['quantity']) * toDouble(l['unit_price']));
    final warn = await PosPrinter.printReceipt(
      session: s,
      doc: {
        'reference': 'Note ${rsLabel(o)}',
        'partner_name': '${o['guests']} couvert(s)',
        'lines': lines,
        'subtotal': sub,
        'tax_total': t.left - sub,
        'total': t.left,
        'created_at': DateTime.now().toIso8601String(),
        'number': 'Note — ${rsLabel(o)}',
      },
      payments: const [],
    );
    if (warn != null && mounted) toast(context, warn, error: true);
  }

  Future<void> _bill() async {
    final s = context.read<Session>();
    if (!s.can('pos.use')) {
      toast(context, tr('L\'encaissement se fait à la caisse : demandez l\'addition (menu ⋮).'), error: true);
      return;
    }
    final reg = _register();
    final session = reg == null ? null : _openPosSession('${reg['id']}');
    if (reg == null || session == null) {
      toast(context, tr('Ouvrez d\'abord la caisse (menu « Caisse ») pour encaisser.'), error: true);
      return;
    }
    final o = _order!;
    final paid = rsPaid(widget.id);
    final open = [
      for (final l in rsLines(widget.id).where((l) => l['status'] != 'cancelled'))
        if (toDouble(l['quantity']) - (paid['${l['id']}'] ?? 0) > 0.0001) l,
    ];
    if (open.isEmpty) {
      toast(context, tr('Tout est déjà réglé.'));
      return;
    }
    final pick = await showDialog<Map<String, double>>(
      context: context,
      builder: (_) => _SplitDialog(lines: open, paid: paid, guests: ((o['guests'] as num?) ?? 1).toInt()),
    );
    if (pick == null || pick.isEmpty || !mounted) return;
    final byId = {for (final l in open) '${l['id']}': l};
    final receiptLines = [
      for (final e in pick.entries)
        {
          'id': newId(),
          'product_id': byId[e.key]!['product_id'],
          'description': byId[e.key]!['description'],
          'quantity': e.value,
          'unit_price': toDouble(byId[e.key]!['unit_price']),
          'discount_percent': 0,
          'tax_rate': toDouble(byId[e.key]!['tax_rate']),
        },
    ];
    final everything = open.every((l) => (pick['${l['id']}'] ?? 0) + (paid['${l['id']}'] ?? 0) >= toDouble(l['quantity']) - 0.0001);
    final doc = await posCheckout(
      context,
      register: reg,
      session: session,
      lines: receiptLines,
      customer: o['partner_id'] == null ? null : LocalDb.get('partners', o['partner_id'] as String),
      extra: {'rs_order_id': widget.id, 'rs_bill': pick, 'notes': '${rsLabel(o)} · ${o['number'] ?? ''}'},
      guests: everything ? ((o['guests'] as num?) ?? 1).toInt() : 1,
      title: rsLabel(o),
    );
    if (doc == null || !mounted) return;
    if (everything) {
      // Affichage immédiat : le serveur clôture la commande à la synchronisation.
      await LocalDb.put('rs_orders', {...o, 'status': 'closed', 'closed_at': DateTime.now().toUtc().toIso8601String()});
      if (mounted) Navigator.pop(context);
    } else {
      setState(() {});
    }
  }

  @override
  Widget build(BuildContext context) {
    context.watch<Session>();
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('rs_orders'),
      builder: (context, _, _) => ValueListenableBuilder(
        valueListenable: LocalDb.listen('rs_order_lines'),
        builder: (context, _, _) {
          final o = _order;
          if (o == null) {
            return Scaffold(
              appBar: AppBar(),
              body: EmptyState(icon: Icons.receipt_long, title: tr('Commande introuvable'), message: tr('Elle a peut-être été supprimée.')),
            );
          }
          final wide = MediaQuery.sizeOf(context).width >= 900;
          final ticket = _TicketPanel(orderId: widget.id, onLine: _lineMenu, onSend: _send, onBill: _bill, open: o['status'] == 'open');
          final catalog = _MenuCatalog(
            category: _category,
            search: _search,
            course: _course,
            onCategory: (c) => setState(() => _category = c),
            onCourse: (c) => setState(() => _course = c),
            onSearch: () => setState(() {}),
            onAdd: (p) => _add(p),
            onLongPress: _addDetailed,
          );
          final lines = rsLines(widget.id);
          final pendingCount = lines.where((l) => l['status'] == 'pending').length;
          final appBar = AppBar(
            title: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(tr(rsLabel(o))),
                Text(
                  tr('${o['number'] ?? 'Numéro attribué à la synchro'} · ${o['guests']} couv. · ${o['waiter_name'] ?? ''} · ${elapsed(o['opened_at'])}'),
                  style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor),
                ),
              ],
            ),
            actions: [
              if (o['bill_requested_at'] != null)
                Padding(
                  padding: EdgeInsets.only(right: 8),
                  child: Center(child: StatusChip(tr('Addition demandée'), color: YColors.danger)),
                ),
              PopupMenuButton<String>(
                onSelected: _orderMenu,
                itemBuilder: (_) => [
                  if (o['status'] == 'open') PopupMenuItem(value: 'bill', child: Text(tr(o['bill_requested_at'] == null ? 'Demander l\'addition' : 'Retirer la demande d\'addition'))),
                  if (o['status'] == 'open') PopupMenuItem(value: 'guests', child: Text(tr('Nombre de couverts'))),
                  if (o['status'] == 'open') PopupMenuItem(value: 'move', child: Text(tr('Transférer vers une autre table'))),
                  PopupMenuItem(value: 'print', child: Text(tr('Imprimer la note'))),
                  PopupMenuItem(value: 'tickets', child: Text(tr('Réimprimer les bons de préparation'))),
                  if (o['status'] == 'open' && rsTotals(widget.id).left <= 0 && lines.isNotEmpty) PopupMenuItem(value: 'free', child: Text(tr('Libérer la table'))),
                  if (o['status'] == 'open')
                    PopupMenuItem(
                      value: 'cancel',
                      child: Text(tr('Annuler la commande'), style: TextStyle(color: YColors.danger)),
                    ),
                ],
              ),
            ],
          );
          if (o['status'] != 'open') {
            return Scaffold(
              appBar: appBar,
              body: Column(
                children: [
                  MaterialBanner(
                    content: Text(tr(o['status'] == 'closed' ? 'Commande réglée le ${dateTimeFr(o['closed_at'])}.' : 'Commande annulée${o['cancel_reason'] != null ? ' : ${o['cancel_reason']}' : ''}.')),
                    actions: [TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Retour')))],
                  ),
                  Expanded(child: ticket),
                ],
              ),
            );
          }
          if (wide) {
            return Scaffold(
              appBar: appBar,
              body: Row(
                children: [
                  Expanded(child: catalog),
                  SizedBox(
                    width: 420,
                    child: Padding(padding: const EdgeInsets.fromLTRB(0, 8, 12, 12), child: ticket),
                  ),
                ],
              ),
            );
          }
          return DefaultTabController(
            length: 2,
            child: Scaffold(
              appBar: AppBar(
                title: appBar.title,
                actions: appBar.actions,
                bottom: TabBar(
                  tabs: [
                    Tab(text: tr('Carte')),
                    Tab(text: tr('Commande${pendingCount > 0 ? ' ($pendingCount à envoyer)' : ''}')),
                  ],
                ),
              ),
              body: TabBarView(
                children: [
                  catalog,
                  Padding(padding: const EdgeInsets.all(8), child: ticket),
                ],
              ),
            ),
          );
        },
      ),
    );
  }
}

Future<String?> _askReason(BuildContext context, String title) async {
  final c = TextEditingController();
  final presets = ['Erreur de saisie', 'Client a changé d\'avis', 'Trop d\'attente', 'Rupture', 'Plat renvoyé'];
  final ok = await showDialog<bool>(
    context: context,
    builder: (d) => StatefulBuilder(
      builder: (d, set) => YAlertDialog(
        title: Text(tr(title)),
        content: SizedBox(
          width: 380,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              TextField(
                controller: c,
                autofocus: true,
                decoration: InputDecoration(labelText: tr('Motif (obligatoire)')),
                onChanged: (_) => set(() {}),
              ),
              const SizedBox(height: 8),
              Wrap(
                spacing: 6,
                runSpacing: 6,
                children: [for (final p in presets) ActionChip(label: Text(tr(p)), onPressed: () => set(() => c.text = p))],
              ),
            ],
          ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(d, false), child: Text(tr('Retour'))),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: YColors.danger),
            onPressed: c.text.trim().isEmpty ? null : () => Navigator.pop(d, true),
            child: Text(tr('Confirmer')),
          ),
        ],
      ),
    ),
  );
  return ok == true ? c.text.trim() : null;
}

class _MenuCatalog extends StatelessWidget {
  const _MenuCatalog({
    required this.category,
    required this.search,
    required this.course,
    required this.onCategory,
    required this.onCourse,
    required this.onSearch,
    required this.onAdd,
    required this.onLongPress,
  });

  final String? category;
  final TextEditingController search;
  final int course;
  final ValueChanged<String?> onCategory;
  final ValueChanged<int> onCourse;
  final VoidCallback onSearch;
  final ValueChanged<Map<String, dynamic>> onAdd;
  final ValueChanged<Map<String, dynamic>> onLongPress;

  @override
  Widget build(BuildContext context) {
    final cur = context.read<Session>().currency;
    // La carte = les catégories préparées par les postes (cuisine, bar…) ; « Autres » montre le reste du catalogue.
    final menuCats = {for (final st in LocalDb.all('rs_stations')) ...((st['category_ids'] as List?) ?? []).map((e) => '$e')};
    final categories = LocalDb.all('product_categories').where((c) => menuCats.isEmpty || menuCats.contains(c['id'])).toList()..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));
    final q = search.text.trim().toLowerCase();
    final others = category == '_other';
    final products =
        LocalDb.all('products')
            .where((p) => p['active'] != false && p['sale_price'] != null)
            .where((p) => others ? !menuCats.contains(p['category_id']) : (category == null ? (menuCats.isEmpty || menuCats.contains(p['category_id']) || q.isNotEmpty) : p['category_id'] == category))
            .where((p) => q.isEmpty || '${p['name']} ${p['sku'] ?? ''}'.toLowerCase().contains(q))
            .toList()
          ..sort((a, b) => '${a['name']}'.toLowerCase().compareTo('${b['name']}'.toLowerCase()));
    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 8, 12, 0),
          child: TextField(
            controller: search,
            decoration: InputDecoration(prefixIcon: Icon(Icons.search), hintText: tr('Chercher un plat, une boisson…')),
            onChanged: (_) => onSearch(),
          ),
        ),
        SizedBox(
          height: 46,
          child: ListView(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.fromLTRB(12, 6, 12, 0),
            children: [
              Padding(
                padding: const EdgeInsets.only(right: 6),
                child: ChoiceChip(label: Text(tr('Tout')), selected: category == null, onSelected: (_) => onCategory(null)),
              ),
              for (final c in categories)
                Padding(
                  padding: const EdgeInsets.only(right: 6),
                  child: ChoiceChip(label: Text(tr('${c['name']}')), selected: category == c['id'], onSelected: (_) => onCategory('${c['id']}')),
                ),
              if (menuCats.isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(right: 6),
                  child: ChoiceChip(label: Text(tr('Autres articles')), selected: others, onSelected: (_) => onCategory('_other')),
                ),
            ],
          ),
        ),
        SizedBox(
          height: 42,
          child: ListView(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.fromLTRB(12, 4, 12, 0),
            children: [
              Padding(
                padding: const EdgeInsets.only(right: 6, top: 8),
                child: Text(tr('Suite :'), style: TextStyle(color: Theme.of(context).hintColor)),
              ),
              Padding(
                padding: const EdgeInsets.only(right: 6),
                child: ChoiceChip(visualDensity: VisualDensity.compact, label: Text(tr('Auto')), selected: course < 0, onSelected: (_) => onCourse(-1)),
              ),
              for (final e in rsCourses.entries)
                Padding(
                  padding: const EdgeInsets.only(right: 6),
                  child: ChoiceChip(visualDensity: VisualDensity.compact, label: Text(tr(e.value)), selected: course == e.key, onSelected: (_) => onCourse(e.key)),
                ),
            ],
          ),
        ),
        Expanded(
          child: products.isEmpty
              ? EmptyState(icon: Icons.restaurant_menu, title: tr('Aucun article'), message: tr('Ajoutez vos plats et boissons dans « Produits & services ».'))
              : GridView.builder(
                  padding: const EdgeInsets.all(12),
                  gridDelegate: const SliverGridDelegateWithMaxCrossAxisExtent(maxCrossAxisExtent: 170, mainAxisExtent: 92, crossAxisSpacing: 8, mainAxisSpacing: 8),
                  itemCount: products.length,
                  itemBuilder: (_, i) {
                    final p = products[i];
                    final ttc = toDouble(p['sale_price']) * (1 + toDouble(p['tax_rate']) / 100);
                    return Material(
                      color: Theme.of(context).colorScheme.surface,
                      borderRadius: BorderRadius.circular(12),
                      child: InkWell(
                        borderRadius: BorderRadius.circular(12),
                        onTap: () => onAdd(p),
                        onLongPress: () => onLongPress(p),
                        onSecondaryTap: () => onLongPress(p),
                        child: Container(
                          padding: const EdgeInsets.all(10),
                          decoration: BoxDecoration(
                            borderRadius: BorderRadius.circular(12),
                            border: Border.all(color: Theme.of(context).dividerColor),
                          ),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Expanded(
                                child: Text(
                                  tr('${p['name']}'),
                                  maxLines: 2,
                                  overflow: TextOverflow.ellipsis,
                                  style: const TextStyle(fontWeight: FontWeight.w700),
                                ),
                              ),
                              Text(
                                tr(money(ttc, cur)),
                                style: const TextStyle(color: YColors.blue, fontWeight: FontWeight.w700),
                              ),
                            ],
                          ),
                        ),
                      ),
                    );
                  },
                ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 0, 12, 8),
          child: Text(tr('Appui long : quantité, suite et précisions (cuisson, sans piment…).'), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
        ),
      ],
    );
  }
}

class _TicketPanel extends StatelessWidget {
  const _TicketPanel({required this.orderId, required this.onLine, required this.onSend, required this.onBill, required this.open});

  final String orderId;
  final ValueChanged<Map<String, dynamic>> onLine;
  final Future<void> Function({Set<int>? courses}) onSend;
  final VoidCallback onBill;
  final bool open;

  @override
  Widget build(BuildContext context) {
    final cur = context.read<Session>().currency;
    final sync = context.read<SyncEngine>();
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('sales_documents'),
      builder: (context, _, _) {
        final lines = rsLines(orderId);
        final paid = rsPaid(orderId);
        final t = rsTotals(orderId);
        final pending = lines.where((l) => l['status'] == 'pending').toList();
        final pendingCourses = pending.map((l) => ((l['course'] as num?) ?? 2).toInt()).toSet().toList()..sort();
        final groups = <int, List<Map<String, dynamic>>>{};
        for (final l in lines) {
          groups.putIfAbsent(((l['course'] as num?) ?? 2).toInt(), () => []).add(l);
        }
        final keys = groups.keys.toList()..sort();
        return Card(
          margin: EdgeInsets.zero,
          child: Column(
            children: [
              Expanded(
                child: lines.isEmpty
                    ? Center(
                        child: Text(tr('Touchez un article de la carte pour l\'ajouter'), style: TextStyle(color: Theme.of(context).hintColor)),
                      )
                    : ListView(
                        padding: const EdgeInsets.symmetric(vertical: 6),
                        children: [
                          for (final k in keys) ...[
                            Padding(
                              padding: const EdgeInsets.fromLTRB(14, 8, 14, 2),
                              child: Text(
                                tr((rsCourses[k] ?? 'Suite $k').toUpperCase()),
                                style: TextStyle(fontSize: 11, fontWeight: FontWeight.w800, color: Theme.of(context).hintColor, letterSpacing: 1),
                              ),
                            ),
                            for (final l in groups[k]!)
                              InkWell(
                                onTap: () => onLine(l),
                                child: Padding(
                                  padding: const EdgeInsets.fromLTRB(14, 6, 8, 6),
                                  child: Row(
                                    children: [
                                      if (open && l['status'] == 'pending') ...[
                                        InkWell(
                                          onTap: () => toDouble(l['quantity']) > 1
                                              ? sync.save('rs_order_lines', {'id': l['id'], 'quantity': toDouble(l['quantity']) - 1})
                                              : sync.remove('rs_order_lines', '${l['id']}'),
                                          child: const Icon(Icons.remove_circle_outline, size: 22),
                                        ),
                                        SizedBox(
                                          width: 30,
                                          child: Text(
                                            tr(qty(toDouble(l['quantity']))),
                                            textAlign: TextAlign.center,
                                            style: const TextStyle(fontWeight: FontWeight.w800),
                                          ),
                                        ),
                                        InkWell(
                                          onTap: () => sync.save('rs_order_lines', {'id': l['id'], 'quantity': toDouble(l['quantity']) + 1}),
                                          child: const Icon(Icons.add_circle_outline, size: 22),
                                        ),
                                        const SizedBox(width: 8),
                                      ] else
                                        SizedBox(
                                          width: 34,
                                          child: Text(tr('${qty(toDouble(l['quantity']))}×'), style: const TextStyle(fontWeight: FontWeight.w800)),
                                        ),
                                      Expanded(
                                        child: Column(
                                          crossAxisAlignment: CrossAxisAlignment.start,
                                          children: [
                                            Text(
                                              tr('${l['description']}'),
                                              style: TextStyle(
                                                fontWeight: FontWeight.w600,
                                                decoration: l['status'] == 'cancelled' ? TextDecoration.lineThrough : null,
                                                color: l['status'] == 'cancelled' ? Theme.of(context).hintColor : null,
                                              ),
                                            ),
                                            if (l['note'] != null && '${l['note']}'.isNotEmpty)
                                              Text(
                                                tr('› ${l['note']}'),
                                                style: const TextStyle(fontSize: 12, color: YColors.warning, fontWeight: FontWeight.w600),
                                              ),
                                            if (l['status'] == 'cancelled' && l['cancel_reason'] != null)
                                              Text(tr('Annulé : ${l['cancel_reason']}'), style: const TextStyle(fontSize: 12, color: YColors.danger)),
                                            const SizedBox(height: 2),
                                            Wrap(
                                              spacing: 6,
                                              children: [
                                                StatusChip(
                                                  '${rsLineStatus[l['status']] ?? l['status']}${l['status'] == 'sent' || l['status'] == 'preparing' ? ' · ${elapsed(l['sent_at'])}' : ''}',
                                                  color: rsLineColor(l['status'] as String?),
                                                ),
                                                if ((paid['${l['id']}'] ?? 0) > 0)
                                                  StatusChip(
                                                    (paid['${l['id']}'] ?? 0) >= toDouble(l['quantity']) ? 'Réglé' : '${qty(paid['${l['id']}'])} réglé(s)',
                                                    color: YColors.success,
                                                    icon: Icons.check,
                                                  ),
                                              ],
                                            ),
                                          ],
                                        ),
                                      ),
                                      Text(
                                        tr(money(rsLineTotal(l), cur)),
                                        style: TextStyle(fontWeight: FontWeight.w700, decoration: l['status'] == 'cancelled' ? TextDecoration.lineThrough : null),
                                      ),
                                    ],
                                  ),
                                ),
                              ),
                          ],
                        ],
                      ),
              ),
              const Divider(height: 1),
              Padding(
                padding: const EdgeInsets.fromLTRB(14, 10, 14, 4),
                child: Column(
                  children: [
                    Row(
                      children: [
                        Text(tr('Total'), style: TextStyle(fontSize: 16, fontWeight: FontWeight.w700)),
                        const Spacer(),
                        Text(tr(money(t.total, cur)), style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800)),
                      ],
                    ),
                    if (t.paid > 0)
                      Row(
                        children: [
                          Text(tr('Déjà réglé')),
                          const Spacer(),
                          Text(
                            tr(money(t.paid, cur)),
                            style: const TextStyle(color: YColors.success, fontWeight: FontWeight.w700),
                          ),
                        ],
                      ),
                    if (t.paid > 0)
                      Row(
                        children: [
                          Text(tr('Reste à payer'), style: TextStyle(fontWeight: FontWeight.w700)),
                          const Spacer(),
                          Text(tr(money(t.left, cur)), style: const TextStyle(fontWeight: FontWeight.w800)),
                        ],
                      ),
                  ],
                ),
              ),
              if (open)
                Padding(
                  padding: const EdgeInsets.fromLTRB(12, 6, 12, 12),
                  child: Row(
                    children: [
                      Expanded(
                        child: FilledButton.icon(
                          style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52), backgroundColor: YColors.blue),
                          onPressed: pending.isEmpty
                              ? null
                              : () async {
                                  if (pendingCourses.length > 1) {
                                    final pick = await showDialog<Set<int>>(
                                      context: context,
                                      builder: (_) => _SendDialog(courses: pendingCourses, lines: pending),
                                    );
                                    if (pick != null && pick.isNotEmpty) await onSend(courses: pick);
                                  } else {
                                    await onSend();
                                  }
                                },
                          icon: const Icon(Icons.send),
                          label: Text(tr(pending.isEmpty ? 'Rien à envoyer' : 'Envoyer (${pending.length})')),
                        ),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: FilledButton.icon(
                          style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52), backgroundColor: YColors.success),
                          onPressed: t.left > 0 && pending.isEmpty ? onBill : null,
                          icon: const Icon(Icons.payments_outlined),
                          label: Text(tr('Addition')),
                        ),
                      ),
                    ],
                  ),
                ),
            ],
          ),
        );
      },
    );
  }
}

/// Choix des suites à envoyer : par exemple boissons et entrées maintenant, plats plus tard.
class _SendDialog extends StatefulWidget {
  const _SendDialog({required this.courses, required this.lines});

  final List<int> courses;
  final List<Map<String, dynamic>> lines;

  @override
  State<_SendDialog> createState() => _SendDialogState();
}

class _SendDialogState extends State<_SendDialog> {
  late final Set<int> _pick = widget.courses.toSet();

  @override
  Widget build(BuildContext context) {
    return YAlertDialog(
      title: Text(tr('Que faut-il envoyer maintenant ?')),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          for (final c in widget.courses)
            CheckboxListTile(
              value: _pick.contains(c),
              onChanged: (v) => setState(() => v == true ? _pick.add(c) : _pick.remove(c)),
              title: Text(tr(rsCourses[c] ?? 'Suite $c')),
              subtitle: Text(
                tr(widget.lines.where((l) => ((l['course'] as num?) ?? 2) == c).map((l) => '${qty(toDouble(l['quantity']))} ${l['description']}').join(', ')),
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
              ),
            ),
          Text(tr('Les suites non cochées restent « À envoyer » : envoyez-les quand la table est prête.'), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
        ],
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Annuler'))),
        FilledButton(onPressed: _pick.isEmpty ? null : () => Navigator.pop(context, _pick), child: Text(tr('Envoyer'))),
      ],
    );
  }
}

/// Addition : tout régler, ou choisir ce que paie chaque convive.
class _SplitDialog extends StatefulWidget {
  const _SplitDialog({required this.lines, required this.paid, required this.guests});

  final List<Map<String, dynamic>> lines;
  final Map<String, double> paid;
  final int guests;

  @override
  State<_SplitDialog> createState() => _SplitDialogState();
}

class _SplitDialogState extends State<_SplitDialog> {
  late final Map<String, double> _left = {for (final l in widget.lines) '${l['id']}': toDouble(l['quantity']) - (widget.paid['${l['id']}'] ?? 0)};
  late final Map<String, double> _pick = Map.of(_left);

  double get _total => widget.lines.fold(0, (s, l) => s + (_pick['${l['id']}'] ?? 0) * rsUnitTtc(l));

  @override
  Widget build(BuildContext context) {
    final cur = context.read<Session>().currency;
    final all = _pick.entries.every((e) => (e.value - (_left[e.key] ?? 0)).abs() < 0.0001);
    return YAlertDialog(
      title: Text(tr('Addition')),
      content: SizedBox(
        width: 460,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Wrap(
              spacing: 8,
              children: [
                ChoiceChip(label: Text(tr('Toute la table')), selected: all, onSelected: (_) => setState(() => _pick.addAll(_left))),
                ChoiceChip(label: Text(tr('Choisir les articles')), selected: !all, onSelected: (_) => setState(() => _pick.updateAll((_, _) => 0))),
              ],
            ),
            const SizedBox(height: 8),
            Flexible(
              child: ListView(
                shrinkWrap: true,
                children: [
                  for (final l in widget.lines)
                    Builder(
                      builder: (_) {
                        final id = '${l['id']}';
                        final q = _pick[id] ?? 0;
                        return ListTile(
                          dense: true,
                          contentPadding: EdgeInsets.zero,
                          title: Text(tr('${l['description']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                          subtitle: Text(tr('${qty(_left[id])} restant(s) · ${money(rsUnitTtc(l), cur)}')),
                          trailing: Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              IconButton(onPressed: q > 0 ? () => setState(() => _pick[id] = (q - 1).clamp(0, _left[id]!)) : null, icon: const Icon(Icons.remove_circle_outline)),
                              SizedBox(
                                width: 32,
                                child: Text(
                                  tr(qty(q)),
                                  textAlign: TextAlign.center,
                                  style: const TextStyle(fontWeight: FontWeight.w800),
                                ),
                              ),
                              IconButton(onPressed: q < _left[id]! ? () => setState(() => _pick[id] = (q + 1).clamp(0, _left[id]!)) : null, icon: const Icon(Icons.add_circle_outline)),
                            ],
                          ),
                        );
                      },
                    ),
                ],
              ),
            ),
            const Divider(),
            Row(
              children: [
                Text(tr('À encaisser'), style: TextStyle(fontWeight: FontWeight.w700)),
                const Spacer(),
                Text(tr(money(_total, cur)), style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
              ],
            ),
            if (all && widget.guests > 1) Text(tr('Partage en parts égales possible à l\'étape suivante (${widget.guests} couverts).'), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
          ],
        ),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Annuler'))),
        FilledButton(
          style: FilledButton.styleFrom(backgroundColor: YColors.success),
          onPressed: _total <= 0 ? null : () => Navigator.pop(context, Map<String, double>.fromEntries(_pick.entries.where((e) => e.value > 0))),
          child: Text(tr('Encaisser')),
        ),
      ],
    );
  }
}

// ======================================================================
// Écran cuisine / bar
// ======================================================================

class KitchenView extends StatefulWidget {
  const KitchenView({super.key});

  @override
  State<KitchenView> createState() => _KitchenViewState();
}

class _KitchenViewState extends State<KitchenView> {
  String? _station = LocalDb.meta.get('rs_station') as String?;
  bool _showReady = true;
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    // L'écran cuisine se rafraîchit souvent : nouvelles commandes, annulations.
    _timer = Timer.periodic(const Duration(seconds: 10), (_) {
      if (!mounted) return;
      context.read<SyncEngine>().sync();
      setState(() {});
    });
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  Future<void> _advance(List<Map<String, dynamic>> lines, String to) async {
    final sync = context.read<SyncEngine>();
    try {
      for (final l in lines) {
        if ((_flow[to] ?? 0) > (_flow[l['status']] ?? 0)) await sync.save('rs_order_lines', {'id': l['id'], 'status': to});
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('rs_order_lines'),
      builder: (context, _, _) {
        final stations = LocalDb.all('rs_stations')..sort((a, b) => ((a['sort'] as num?) ?? 0).compareTo((b['sort'] as num?) ?? 0));
        final active = LocalDb.all('rs_order_lines').where((l) => ['sent', 'preparing', 'ready'].contains(l['status']) && (_station == null || l['station_id'] == _station)).toList();
        // Un bon = une commande et un envoi (lignes envoyées à la même minute)
        final tickets = <String, List<Map<String, dynamic>>>{};
        for (final l in active) {
          final sent = DateTime.tryParse('${l['sent_at'] ?? ''}')?.toUtc();
          final key = '${l['order_id']}|${sent == null ? '' : sent.toIso8601String().substring(0, 16)}';
          tickets.putIfAbsent(key, () => []).add(l);
        }
        final list = tickets.entries.toList()..sort((a, b) => '${a.value.first['sent_at']}'.compareTo('${b.value.first['sent_at']}'));
        final todo = list.where((e) => e.value.any((l) => l['status'] != 'ready')).toList();
        final ready = list.where((e) => e.value.every((l) => l['status'] == 'ready')).toList();
        final cancelled = LocalDb.all('rs_order_lines')
            .where((l) => l['status'] == 'cancelled' && l['sent_at'] != null && minutesSince(l['updated_at']) < 10 && (_station == null || l['station_id'] == _station))
            .toList();
        return Column(
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 10, 16, 4),
              child: Row(
                children: [
                  Expanded(
                    child: SingleChildScrollView(
                      scrollDirection: Axis.horizontal,
                      child: Row(
                        children: [
                          Padding(
                            padding: const EdgeInsets.only(right: 8),
                            child: ChoiceChip(label: Text(tr('Tous les postes')), selected: _station == null, onSelected: (_) => _pick(null)),
                          ),
                          for (final s in stations)
                            Padding(
                              padding: const EdgeInsets.only(right: 8),
                              child: ChoiceChip(
                                avatar: Icon(s['kind'] == 'bar' ? Icons.local_bar : Icons.soup_kitchen, size: 18),
                                label: Text(tr('${s['name']}')),
                                selected: _station == s['id'],
                                onSelected: (_) => _pick('${s['id']}'),
                              ),
                            ),
                        ],
                      ),
                    ),
                  ),
                  Text(tr('${todo.length} bon(s) en cours'), style: const TextStyle(fontWeight: FontWeight.w700)),
                  IconButton(
                    tooltip: trn(_showReady ? 'Masquer les prêts' : 'Afficher les prêts'),
                    onPressed: () => setState(() => _showReady = !_showReady),
                    icon: Icon(_showReady ? Icons.visibility : Icons.visibility_off),
                  ),
                ],
              ),
            ),
            if (cancelled.isNotEmpty)
              Container(
                width: double.infinity,
                margin: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(color: YColors.danger.withValues(alpha: 0.1), borderRadius: BorderRadius.circular(10)),
                child: Text(
                  tr('Annulé : ${cancelled.map((l) => '${qty(toDouble(l['quantity']))} ${l['description']} (${rsLabel(LocalDb.get('rs_orders', l['order_id'] as String) ?? {})})').join(' · ')}'),
                  style: const TextStyle(color: YColors.danger, fontWeight: FontWeight.w700),
                ),
              ),
            Expanded(
              child: todo.isEmpty && (!_showReady || ready.isEmpty)
                  ? EmptyState(
                      icon: Icons.soup_kitchen_outlined,
                      title: tr('Rien à préparer'),
                      message: tr('Les commandes envoyées par la salle apparaissent ici, des plus anciennes aux plus récentes.'),
                    )
                  : ListView(
                      padding: const EdgeInsets.all(12),
                      children: [
                        Wrap(spacing: 10, runSpacing: 10, children: [for (final t in todo) _ticket(t.value)]),
                        if (_showReady && ready.isNotEmpty) ...[
                          Padding(
                            padding: const EdgeInsets.fromLTRB(4, 16, 4, 8),
                            child: Text(
                              tr('PRÊTS À SERVIR'),
                              style: TextStyle(fontWeight: FontWeight.w800, color: Theme.of(context).hintColor, letterSpacing: 1),
                            ),
                          ),
                          Wrap(spacing: 10, runSpacing: 10, children: [for (final t in ready) _ticket(t.value, done: true)]),
                        ],
                      ],
                    ),
            ),
          ],
        );
      },
    );
  }

  void _pick(String? id) {
    LocalDb.meta.put('rs_station', id);
    setState(() => _station = id);
  }

  Widget _ticket(List<Map<String, dynamic>> lines, {bool done = false}) {
    final o = LocalDb.get('rs_orders', lines.first['order_id'] as String) ?? {};
    final m = minutesSince(lines.first['sent_at']);
    final late = m >= 20 ? YColors.danger : (m >= 10 ? YColors.warning : YColors.success);
    final sorted = lines..sort((a, b) => ((a['course'] as num?) ?? 0).compareTo((b['course'] as num?) ?? 0));
    final kitchenOnly = !context.read<Session>().can('restaurant.serve');
    return SizedBox(
      width: 270,
      child: Card(
        margin: EdgeInsets.zero,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(12),
          side: BorderSide(color: done ? YColors.success : late, width: 2),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Container(
              padding: const EdgeInsets.fromLTRB(12, 8, 12, 8),
              decoration: BoxDecoration(
                color: (done ? YColors.success : late).withValues(alpha: 0.12),
                borderRadius: const BorderRadius.vertical(top: Radius.circular(10)),
              ),
              child: Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(tr(rsLabel(o)), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 17)),
                        Text(tr('${o['guests'] ?? 1} couv. · ${o['waiter_name'] ?? ''}'), style: const TextStyle(fontSize: 12)),
                      ],
                    ),
                  ),
                  Text(
                    tr(elapsed(lines.first['sent_at'])),
                    style: TextStyle(fontWeight: FontWeight.w800, color: done ? YColors.success : late),
                  ),
                ],
              ),
            ),
            for (final l in sorted)
              InkWell(
                onTap: done && kitchenOnly ? null : () => _advance([l], l['status'] == 'sent' ? 'preparing' : (l['status'] == 'preparing' ? 'ready' : 'served')),
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(12, 8, 12, 8),
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Icon(
                        l['status'] == 'ready' ? Icons.check_circle : (l['status'] == 'preparing' ? Icons.local_fire_department : Icons.radio_button_unchecked),
                        color: rsLineColor(l['status'] as String?),
                        size: 22,
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              tr('${qty(toDouble(l['quantity']))} × ${l['description']}'),
                              style: TextStyle(fontSize: 16, fontWeight: FontWeight.w700, decoration: l['status'] == 'ready' ? TextDecoration.lineThrough : null),
                            ),
                            if (l['note'] != null && '${l['note']}'.isNotEmpty)
                              Text(
                                tr('› ${l['note']}'),
                                style: const TextStyle(color: YColors.danger, fontWeight: FontWeight.w700),
                              ),
                            Text(tr(rsCourses[l['course']] ?? ''), style: TextStyle(fontSize: 11, color: Theme.of(context).hintColor)),
                          ],
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            Padding(
              padding: const EdgeInsets.fromLTRB(8, 0, 8, 8),
              child: done
                  ? OutlinedButton.icon(onPressed: () => _advance(lines, 'served'), icon: const Icon(Icons.room_service_outlined), label: Text(tr('Servi / récupéré')))
                  : Row(
                      children: [
                        if (lines.any((l) => l['status'] == 'sent'))
                          Expanded(
                            child: OutlinedButton(onPressed: () => _advance(lines, 'preparing'), child: Text(tr('Je lance'))),
                          ),
                        if (lines.any((l) => l['status'] == 'sent')) const SizedBox(width: 6),
                        Expanded(
                          child: FilledButton(
                            style: FilledButton.styleFrom(backgroundColor: YColors.success),
                            onPressed: () => _advance(lines, 'ready'),
                            child: Text(tr('Tout est prêt')),
                          ),
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

/// Écran plein pour la cuisine (rôle Cuisine / bar).
class KitchenScreen extends StatelessWidget {
  const KitchenScreen({super.key});

  @override
  Widget build(BuildContext context) => Column(
    children: [
      PageHeader(title: tr('Cuisine & bar'), subtitle: tr('Touchez un plat pour le lancer puis le marquer prêt')),
      const Expanded(child: KitchenView()),
    ],
  );
}

// ======================================================================
// Réglages : salles, postes, impression
// ======================================================================

class _SettingsTab extends StatefulWidget {
  const _SettingsTab();

  @override
  State<_SettingsTab> createState() => _SettingsTabState();
}

class _SettingsTabState extends State<_SettingsTab> {
  Future<void> _floor([Map<String, dynamic>? f]) async {
    final sync = context.read<SyncEngine>();
    final c = TextEditingController(text: '${f?['name'] ?? ''}');
    final r = await showDialog<String>(
      context: context,
      builder: (d) => YAlertDialog(
        title: Text(tr(f == null ? 'Nouvelle salle' : 'Salle')),
        content: TextField(
          controller: c,
          autofocus: true,
          decoration: InputDecoration(labelText: tr('Nom (Salle, Terrasse, Étage…)')),
        ),
        actions: [
          if (f != null)
            TextButton(
              onPressed: () => Navigator.pop(d, 'delete'),
              child: Text(tr('Supprimer'), style: TextStyle(color: YColors.danger)),
            ),
          TextButton(onPressed: () => Navigator.pop(d), child: Text(tr('Annuler'))),
          FilledButton(onPressed: () => Navigator.pop(d, 'save'), child: Text(tr('Enregistrer'))),
        ],
      ),
    );
    try {
      if (r == 'delete') await sync.remove('rs_floors', '${f!['id']}');
      if (r == 'save' && c.text.trim().isNotEmpty) await sync.save('rs_floors', {'id': f?['id'] ?? newId(), 'name': c.text.trim(), 'sort': f?['sort'] ?? LocalDb.all('rs_floors').length});
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _station([Map<String, dynamic>? s]) async {
    final sync = context.read<SyncEngine>();
    final c = TextEditingController(text: '${s?['name'] ?? ''}');
    var kind = '${s?['kind'] ?? 'kitchen'}';
    final cats = <String>{...((s?['category_ids'] as List?) ?? []).map((e) => '$e')};
    final all = LocalDb.all('product_categories')..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));
    final r = await showDialog<String>(
      context: context,
      builder: (d) => StatefulBuilder(
        builder: (d, set) => YAlertDialog(
          title: Text(tr(s == null ? 'Nouveau poste' : 'Poste')),
          content: SizedBox(
            width: 420,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                TextField(
                  controller: c,
                  autofocus: true,
                  decoration: InputDecoration(labelText: tr('Nom (Cuisine, Bar, Grill…)')),
                ),
                const SizedBox(height: 8),
                SegmentedButton<String>(
                  segments: [
                    ButtonSegment(value: 'kitchen', label: Text(tr('Cuisine')), icon: Icon(Icons.soup_kitchen)),
                    ButtonSegment(value: 'bar', label: Text(tr('Bar')), icon: Icon(Icons.local_bar)),
                  ],
                  selected: {kind},
                  onSelectionChanged: (v) => set(() => kind = v.first),
                ),
                const SizedBox(height: 12),
                Text(tr('Catégories préparées ici'), style: TextStyle(fontWeight: FontWeight.w600)),
                const SizedBox(height: 6),
                Wrap(
                  spacing: 6,
                  runSpacing: 6,
                  children: [
                    for (final cat in all)
                      FilterChip(label: Text(tr('${cat['name']}')), selected: cats.contains(cat['id']), onSelected: (v) => set(() => v ? cats.add('${cat['id']}') : cats.remove(cat['id']))),
                  ],
                ),
                const SizedBox(height: 6),
                Text(tr('Les articles sans poste vont à la première cuisine.'), style: TextStyle(fontSize: 12, color: Theme.of(d).hintColor)),
              ],
            ),
          ),
          actions: [
            if (s != null)
              TextButton(
                onPressed: () => Navigator.pop(d, 'delete'),
                child: Text(tr('Supprimer'), style: TextStyle(color: YColors.danger)),
              ),
            TextButton(onPressed: () => Navigator.pop(d), child: Text(tr('Annuler'))),
            FilledButton(onPressed: () => Navigator.pop(d, 'save'), child: Text(tr('Enregistrer'))),
          ],
        ),
      ),
    );
    try {
      if (r == 'delete') await sync.remove('rs_stations', '${s!['id']}');
      if (r == 'save' && c.text.trim().isNotEmpty) {
        await sync.save('rs_stations', {'id': s?['id'] ?? newId(), 'name': c.text.trim(), 'kind': kind, 'category_ids': cats.toList(), 'sort': s?['sort'] ?? LocalDb.all('rs_stations').length});
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('rs_stations'),
      builder: (context, _, _) => ValueListenableBuilder(
        valueListenable: LocalDb.listen('rs_floors'),
        builder: (context, _, _) {
          final floors = LocalDb.all('rs_floors')..sort((a, b) => ((a['sort'] as num?) ?? 0).compareTo((b['sort'] as num?) ?? 0));
          final stations = LocalDb.all('rs_stations')..sort((a, b) => ((a['sort'] as num?) ?? 0).compareTo((b['sort'] as num?) ?? 0));
          final cats = {for (final c in LocalDb.all('product_categories')) '${c['id']}': '${c['name']}'};
          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              SectionCard(
                title: tr('Salles'),
                trailing: TextButton.icon(onPressed: () => _floor(), icon: const Icon(Icons.add, size: 18), label: Text(tr('Salle'))),
                child: Column(
                  children: [
                    if (floors.isEmpty) Text(tr('Ajoutez au moins une salle, puis dessinez le plan depuis l\'onglet « Salle ».'), style: TextStyle(color: Theme.of(context).hintColor)),
                    for (final f in floors)
                      ListTile(
                        contentPadding: EdgeInsets.zero,
                        leading: const Icon(Icons.table_restaurant_outlined),
                        title: Text(tr('${f['name']}')),
                        subtitle: Text(tr('${LocalDb.all('rs_tables').where((t) => t['floor_id'] == f['id']).length} table(s)')),
                        onTap: () => _floor(f),
                      ),
                  ],
                ),
              ),
              const SizedBox(height: 12),
              SectionCard(
                title: tr('Postes de préparation'),
                trailing: TextButton.icon(onPressed: () => _station(), icon: const Icon(Icons.add, size: 18), label: Text(tr('Poste'))),
                child: Column(
                  children: [
                    if (stations.isEmpty) Text(tr('Sans poste, tout part en cuisine. Créez par exemple « Cuisine » et « Bar » (boissons).'), style: TextStyle(color: Theme.of(context).hintColor)),
                    for (final s in stations)
                      ListTile(
                        contentPadding: EdgeInsets.zero,
                        leading: Icon(s['kind'] == 'bar' ? Icons.local_bar : Icons.soup_kitchen),
                        title: Text(tr('${s['name']}')),
                        subtitle: Text(tr(((s['category_ids'] as List?) ?? []).map((id) => cats['$id'] ?? '').where((e) => e.isNotEmpty).join(', ').ifEmpty('Tous les articles sans autre poste'))),
                        onTap: () => _station(s),
                      ),
                  ],
                ),
              ),
              const SizedBox(height: 12),
              SectionCard(
                title: tr('Ce poste de travail'),
                child: SwitchListTile(
                  contentPadding: EdgeInsets.zero,
                  title: Text(tr('Imprimer les bons de préparation à l\'envoi')),
                  subtitle: Text(tr('Un bon par poste (cuisine, bar) sur l\'imprimante réglée dans la caisse. Utile si la cuisine n\'a pas d\'écran.')),
                  value: printKitchenTickets,
                  onChanged: (v) async {
                    await LocalDb.meta.put('rs_print_tickets', v);
                    if (mounted) setState(() {});
                  },
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}

extension on String {
  String ifEmpty(String other) => isEmpty ? other : this;
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
  int _days = 0;
  Map<String, dynamic>? _d;
  Object? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final now = DateTime.now();
      final d = await context.read<Session>().api.get(
        'restaurant/stats',
        query: {
          'from': isoDate(now.subtract(Duration(days: _days))),
          'to': isoDate(now),
        },
      );
      if (mounted) setState(() => (_d = asMap(d['data']), _error = null));
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
    final hours = asMap(d['hours']);
    final maxH = hours.values.fold<num>(1, (m, v) => (v as num) > m ? v : m);
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Wrap(
            spacing: 8,
            children: [
              for (final e in {0: 'Aujourd\'hui', 6: '7 jours', 29: '30 jours'}.entries)
                ChoiceChip(
                  label: Text(tr(e.value)),
                  selected: _days == e.key,
                  onSelected: (_) {
                    setState(() => _days = e.key);
                    _load();
                  },
                ),
            ],
          ),
          const SizedBox(height: 12),
          KpiWrap(children: [
              kpi('Chiffre d\'affaires', money(toDouble(d['revenue']), cur), Icons.trending_up, color: YColors.success),
              kpi('Couverts', '${d['covers']}', Icons.people_outline),
              kpi('Ticket moyen / couvert', money(toDouble(d['per_cover']), cur), Icons.restaurant),
              kpi('Commandes (dont à emporter)', '${d['orders']} (${d['takeaway']})', Icons.receipt_long_outlined),
              kpi('Commandes en cours', '${d['open_now']} · ${d['tables']} tables', Icons.table_restaurant_outlined, color: YColors.warning),
              kpi('Lignes annulées après envoi', '${d['cancelled_lines']} (${money(toDouble(d['cancelled_amount']), cur)})', Icons.block, color: YColors.danger),
            ],
          ),
          const SizedBox(height: 12),
          LayoutBuilder(
            builder: (context, box) {
              final cards = [
                SectionCard(
                  title: tr('Articles les plus vendus'),
                  child: Column(
                    children: [
                      for (final t in (d['top'] as List))
                        ListTile(
                          dense: true,
                          contentPadding: EdgeInsets.zero,
                          title: Text(tr('${t['name']}')),
                          subtitle: Text(tr(money(toDouble(t['amount']), cur))),
                          trailing: Text(tr('× ${qty(toDouble(t['quantity']))}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                        ),
                    ],
                  ),
                ),
                Column(
                  children: [
                    SectionCard(
                      title: tr('Temps de préparation moyen'),
                      child: Column(
                        children: [
                          for (final p in (d['prep'] as List))
                            ListTile(
                              dense: true,
                              contentPadding: EdgeInsets.zero,
                              leading: const Icon(Icons.timer_outlined),
                              title: Text(tr('${p['station']}')),
                              subtitle: Text(tr('${p['count']} plat(s)')),
                              trailing: Text(tr('${p['minutes']} min'), style: const TextStyle(fontWeight: FontWeight.w700)),
                            ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 12),
                    SectionCard(
                      title: tr('Par serveur'),
                      child: Column(
                        children: [
                          for (final w in (d['waiters'] as List))
                            ListTile(
                              dense: true,
                              contentPadding: EdgeInsets.zero,
                              title: Text(tr('${w['name']}')),
                              subtitle: Text(tr('${w['orders']} commande(s) · ${w['covers']} couverts')),
                              trailing: Text(tr(money(toDouble(w['revenue']), cur)), style: const TextStyle(fontWeight: FontWeight.w700)),
                            ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 12),
                    SectionCard(
                      title: tr('Affluence (commandes par heure)'),
                      child: Column(
                        children: [
                          for (final e in hours.entries)
                            Padding(
                              padding: const EdgeInsets.symmetric(vertical: 2),
                              child: Row(
                                children: [
                                  SizedBox(width: 44, child: Text(tr('${e.key} h'))),
                                  Expanded(
                                    child: FractionallySizedBox(
                                      alignment: Alignment.centerLeft,
                                      widthFactor: ((e.value as num) / maxH).toDouble(),
                                      child: Container(
                                        height: 14,
                                        decoration: BoxDecoration(color: YColors.blue, borderRadius: BorderRadius.circular(4)),
                                      ),
                                    ),
                                  ),
                                  SizedBox(width: 30, child: Text(tr('${e.value}'), textAlign: TextAlign.right)),
                                ],
                              ),
                            ),
                        ],
                      ),
                    ),
                  ],
                ),
              ];
              return box.maxWidth > 900
                  ? Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Expanded(child: cards[0]),
                        const SizedBox(width: 12),
                        Expanded(child: cards[1]),
                      ],
                    )
                  : Column(children: [cards[0], const SizedBox(height: 12), cards[1]]);
            },
          ),
        ],
      ),
    );
  }
}
