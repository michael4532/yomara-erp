import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/local_db.dart';
import '../../core/stock_calc.dart';
import '../../core/sync_engine.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/filter_bar.dart';
import 'labels_screen.dart' show LabelItem, openLabels;
import '../../i18n/i18n.dart';
import '../../core/json.dart';

const mrpStatus = {'draft': 'Brouillon', 'confirmed': 'Confirmé', 'in_progress': 'En cours', 'done': 'Terminé', 'cancelled': 'Annulé'};

Color mrpColor(String? s) => switch (s) {
      'draft' => YColors.muted,
      'confirmed' => YColors.blue,
      'in_progress' => YColors.warning,
      'done' => YColors.success,
      _ => YColors.danger,
    };

double labourRate(Session s) => toDouble(s.companySettings['mrp_labour_rate'] ?? 0);

Map<String, dynamic>? activeBom(String? productId) => productId == null ? null : LocalDb.all('mrp_boms').where((b) => b['product_id'] == productId && b['active'] != false).firstOrNull;

/// Coût d'un composant : prix d'achat, sinon coût de sa propre nomenclature (calcul identique au serveur).
double componentCost(Map<String, dynamic>? p, double rate, [int depth = 0]) {
  if (p == null) return 0;
  final price = toDouble(p['purchase_price']);
  if (price > 0 || depth > 5) return price;
  final b = activeBom('${p['id']}');
  return b == null ? 0 : bomCost(b, rate, depth + 1).unit;
}

({double material, double labour, double extra, double unit, double price, double margin, double? costRate}) bomCost(Map<String, dynamic> b, double rate, [int depth = 0]) {
  final q = toDouble(b['quantity']) <= 0 ? 1.0 : toDouble(b['quantity']);
  var material = 0.0;
  for (final l in (b['lines'] as List? ?? [])) {
    final p = LocalDb.get('products', l['product_id'] as String?);
    material += toDouble(l['quantity']) * (1 + toDouble(l['loss_percent']) / 100) * componentCost(p, rate, depth);
  }
  final labour = toDouble(b['labour_minutes']) / 60 * rate;
  final extra = toDouble(b['extra_cost']);
  final unit = (material + labour + extra) / q;
  final price = toDouble(LocalDb.get('products', b['product_id'] as String?)?['sale_price']);
  return (material: material / q, labour: labour / q, extra: extra / q, unit: unit, price: price, margin: price - unit, costRate: price > 0 ? unit / price * 100 : null);
}

/// Choix d'un article dans le catalogue (recherche).
Future<Map<String, dynamic>?> pickProduct(BuildContext context, {String title = 'Choisir un article', bool Function(Map<String, dynamic>)? where}) {
  final all = LocalDb.all('products').where((p) => p['active'] != false && (where?.call(p) ?? true)).toList()..sort((a, b) => '${a['name']}'.toLowerCase().compareTo('${b['name']}'.toLowerCase()));
  var q = '';
  return showDialog<Map<String, dynamic>>(
    context: context,
    builder: (c) => StatefulBuilder(
      builder: (c, set) {
        final rows = all.where((p) => q.isEmpty || '${p['name']} ${p['sku'] ?? ''}'.toLowerCase().contains(q)).take(80).toList();
        return YAlertDialog(
          title: Text(tr(title)),
          content: SizedBox(
            width: 440,
            height: 460,
            child: Column(children: [
              TextField(autofocus: true, decoration: InputDecoration(prefixIcon: Icon(Icons.search), hintText: tr('Rechercher…')), onChanged: (v) => set(() => q = v.toLowerCase())),
              const SizedBox(height: 8),
              Expanded(
                child: ListView(children: [
                  for (final p in rows)
                    ListTile(
                      dense: true,
                      title: Text(tr('${p['name']}')),
                      subtitle: Text(tr([if (p['unit'] != null) '${p['unit']}', 'coût ${number(toDouble(p['purchase_price']))}', if (p['type'] == 'service') 'service'].join(' · '))),
                      onTap: () => Navigator.pop(c, p),
                    ),
                ]),
              ),
            ]),
          ),
        );
      },
    ),
  );
}

// ======================================================================
// Écran principal
// ======================================================================

class ProductionScreen extends StatefulWidget {
  const ProductionScreen({super.key});

  @override
  State<ProductionScreen> createState() => _ProductionScreenState();
}

class _ProductionScreenState extends State<ProductionScreen> with SingleTickerProviderStateMixin {
  late final TabController _tabs = TabController(length: 3, vsync: this);

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    final manage = s.can('production.manage');
    return Column(children: [
      PageHeader(
        title: tr('Fabrication'),
        subtitle: tr('Nomenclatures et recettes, ordres de fabrication, coûts de revient'),
        actions: [
          if (manage) IconButton(tooltip: tr('Coût de la main-d\'œuvre'), onPressed: () => _rate(context), icon: const Icon(Icons.tune)),
          if (manage) FilledButton.icon(onPressed: () => newProductionOrder(context), icon: const Icon(Icons.add), label: Text(tr('Ordre de fabrication'))),
        ],
      ),
      TabBar(controller: _tabs, isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [Tab(text: tr('Ordres de fabrication')), Tab(text: tr('Nomenclatures & recettes')), Tab(text: tr('Besoins'))]),
      Expanded(child: TabBarView(controller: _tabs, physics: const NeverScrollableScrollPhysics(), children: const [_OrdersTab(), _BomsTab(), _NeedsTab()])),
    ]);
  }

  Future<void> _rate(BuildContext context) async {
    final s = context.read<Session>();
    final c = TextEditingController(text: '${labourRate(s).round()}');
    final ok = await showDialog<bool>(
      context: context,
      builder: (d) => YAlertDialog(
        title: Text(tr('Coût horaire de la main-d\'œuvre')),
        content: TextField(controller: c, autofocus: true, keyboardType: TextInputType.number, decoration: InputDecoration(suffixText: trn('${s.currency} / h'), helperText: tr('Salaire chargé moyen de l\'atelier ou de la cuisine, ramené à l\'heure.'))),
        actions: [TextButton(onPressed: () => Navigator.pop(d, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(d, true), child: Text(tr('Enregistrer')))],
      ),
    );
    if (ok != true) return;
    try {
      await s.api.put('company', {'settings': {'mrp_labour_rate': toDouble(c.text)}});
      await s.refreshBootstrap();
      if (context.mounted) toast(context, tr('Coût horaire enregistré.'));
    } catch (e) {
      if (context.mounted) toast(context, errorText(e), error: true);
    }
  }
}

// ======================================================================
// Ordres de fabrication
// ======================================================================

class _OrdersTab extends StatefulWidget {
  const _OrdersTab();

  @override
  State<_OrdersTab> createState() => _OrdersTabState();
}

class _OrdersTabState extends State<_OrdersTab> {
  String _filter = 'active';
  String _q = '';
  final Map<String, String?> _f = {};
  FilterPeriod? _period;
  int _gen = 0;

  static bool _statusMatch(Map<String, dynamic> o, String f) => switch (f) {
        '' => true,
        'active' => ['draft', 'confirmed', 'in_progress'].contains(o['status']),
        _ => o['status'] == f,
      };

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('mrp_orders'),
      builder: (context, _, _) {
        final all = LocalDb.all('mrp_orders');
        String productName(Map<String, dynamic> o) => '${LocalDb.get('products', o['product_id'] as String?)?['name'] ?? ''}';
        final base = all
            .where((o) => _q.isEmpty || '${o['number'] ?? ''} ${productName(o)}'.toLowerCase().contains(_q))
            .where((o) => _f['product'] == null || '${o['product_id']}' == _f['product'])
            .where((o) => _period == null || _period!.contains(o['planned_date'] ?? o['created_at']))
            .toList();
        final statuses = {'': 'Tous', 'active': 'À faire et en cours', ...mrpStatus};
        final rows = base.where((o) => _statusMatch(o, _filter)).toList()
          ..sort((a, b) => '${a['planned_date'] ?? '9999'}${a['number'] ?? ''}'.compareTo('${b['planned_date'] ?? '9999'}${b['number'] ?? ''}'));
        if (_filter != 'active') rows.sort((a, b) => '${b['done_at'] ?? b['created_at'] ?? ''}'.compareTo('${a['done_at'] ?? a['created_at'] ?? ''}'));
        final today = isoDate(DateTime.now());
        final month = today.substring(0, 7);
        final lateCount = all.where((o) => ['confirmed', 'in_progress'].contains(o['status']) && o['planned_date'] != null && '${o['planned_date']}'.compareTo(today) < 0).length;
        return Column(children: [
          const SizedBox(height: 12),
          StatStrip(tiles: [
            StatTile(label: tr('À lancer'), value: '${all.where((o) => ['draft', 'confirmed'].contains(o['status'])).length}', detail: tr('brouillons et confirmés'), icon: Icons.playlist_add_check_rounded),
            StatTile(label: tr('En fabrication'), value: '${all.where((o) => o['status'] == 'in_progress').length}', detail: tr('ordres en cours'), icon: Icons.precision_manufacturing_outlined, color: YColors.warning),
            StatTile(label: tr('Terminés ce mois'), value: '${all.where((o) => o['status'] == 'done' && '${o['done_at'] ?? ''}'.startsWith(month)).length}', detail: tr('ordres clôturés'), icon: Icons.task_alt_rounded, color: YColors.success),
            StatTile(label: tr('En retard'), value: '$lateCount', detail: tr('date prévue dépassée'), icon: Icons.schedule_rounded, color: lateCount > 0 ? YColors.danger : YColors.muted),
          ]),
          FilterBar(
            key: ValueKey(_gen),
            onSearch: (v) => setState(() => _q = v.toLowerCase()),
            search: _q,
            searchHint: 'N° ou produit…',
            statuses: statuses,
            status: _filter,
            onStatus: (v) => setState(() => _filter = v),
            statusCounts: {for (final k in statuses.keys) k: base.where((o) => _statusMatch(o, k)).length},
            filters: [FilterSpec('product', 'Produit', filterOptions(all, 'product_id', label: productName), icon: Icons.inventory_2_outlined)],
            values: _f,
            onFilter: (k, v) => setState(() => _f[k] = v),
            showPeriod: true,
            period: _period,
            onPeriod: (p) => setState(() => _period = p),
            count: rows.length,
            countLabel: 'ordre',
            onReset: () => setState(() {
              _filter = 'active';
              _q = '';
              _f.clear();
              _period = null;
              _gen++;
            }),
          ),
          Expanded(
            child: rows.isEmpty
                ? EmptyState(icon: Icons.precision_manufacturing_outlined, title: tr('Aucun ordre de fabrication'), message: tr('Créez d\'abord la nomenclature d\'un produit, puis lancez sa fabrication.'))
                : ResponsiveList<Map<String, dynamic>>(
                    items: rows,
                    columns: const ['N°', 'Produit', 'Quantité', 'Prévu le', 'Statut', 'Coût unitaire'],
                    cells: (o) {
                      final p = LocalDb.get('products', o['product_id'] as String?);
                      final late = ['confirmed', 'in_progress'].contains(o['status']) && o['planned_date'] != null && '${o['planned_date']}'.compareTo(today) < 0;
                      return [
                        Text(tr('${o['number'] ?? '—'}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                        Text(tr('${p?['name'] ?? ''}')),
                        Text(tr('${qty(toDouble(o['status'] == 'done' ? o['produced_qty'] : o['quantity']))} ${p?['unit'] ?? ''}')),
                        Text(tr(o['planned_date'] == null ? '—' : dateFr(o['planned_date'])), style: TextStyle(color: late ? YColors.danger : null, fontWeight: late ? FontWeight.w700 : null)),
                        StatusChip(mrpStatus[o['status']] ?? '${o['status']}', color: mrpColor(o['status'] as String?)),
                        Text(tr(o['unit_cost'] == null ? '—' : money(toDouble(o['unit_cost']), context.read<Session>().currency))),
                      ];
                    },
                    mobileTile: (o) {
                      final p = LocalDb.get('products', o['product_id'] as String?);
                      return ListTile(
                        contentPadding: EdgeInsets.zero,
                        leading: Icon(Icons.precision_manufacturing_outlined, color: mrpColor(o['status'] as String?)),
                        title: Text(tr('${p?['name'] ?? ''} × ${qty(toDouble(o['quantity']))}')),
                        subtitle: Text(tr('${o['number'] ?? ''}${o['planned_date'] != null ? ' · ${dateFr(o['planned_date'])}' : ''}')),
                        trailing: StatusChip(mrpStatus[o['status']] ?? '', color: mrpColor(o['status'] as String?)),
                      );
                    },
                    onTap: (o) => openProductionOrder(context, '${o['id']}'),
                  ),
          ),
        ]);
      },
    );
  }
}

void openProductionOrder(BuildContext context, String id) {
  if (LocalDb.get('mrp_orders', id) == null) return;
  Navigator.of(context).push(MaterialPageRoute(builder: (_) => ProductionOrderScreen(id: id)));
}

Future<void> newProductionOrder(BuildContext context, {String? productId, double? quantity}) async {
  final sync = context.read<SyncEngine>();
  final withBom = LocalDb.all('mrp_boms').where((b) => b['active'] != false && b['kind'] != 'kit').map((b) => b['product_id']).toSet();
  if (withBom.isEmpty) {
    toast(context, tr('Créez d\'abord une nomenclature de fabrication (onglet « Nomenclatures & recettes »).'), error: true);
    return;
  }
  var product = productId == null ? null : LocalDb.get('products', productId);
  final q = TextEditingController(text: quantity == null ? '${toDouble(activeBom(productId)?['quantity'] ?? 1).round()}' : qty(quantity));
  var date = DateTime.now();
  final ok = await showDialog<bool>(
    context: context,
    builder: (c) => StatefulBuilder(
      builder: (c, set) => YAlertDialog(
        title: Text(tr('Nouvel ordre de fabrication')),
        content: SizedBox(
          width: 420,
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            OutlinedButton.icon(
              onPressed: () async {
                final p = await pickProduct(c, title: tr('Produit à fabriquer'), where: (p) => withBom.contains(p['id']));
                if (p != null) {
                  set(() {
                    product = p;
                    q.text = '${toDouble(activeBom('${p['id']}')?['quantity'] ?? 1).round()}';
                  });
                }
              },
              icon: const Icon(Icons.inventory_2_outlined),
              label: Text(tr(product == null ? 'Choisir le produit' : '${product!['name']}')),
            ),
            const SizedBox(height: 12),
            TextField(controller: q, keyboardType: const TextInputType.numberWithOptions(decimal: true), decoration: InputDecoration(labelText: tr('Quantité à fabriquer'), suffixText: trn('${product?['unit'] ?? ''}'))),
            const SizedBox(height: 12),
            OutlinedButton.icon(
              onPressed: () async {
                final d = await showDatePicker(context: c, initialDate: date, firstDate: DateTime.now().subtract(const Duration(days: 30)), lastDate: DateTime(2100));
                if (d != null) set(() => date = d);
              },
              icon: const Icon(Icons.event_outlined),
              label: Text(tr('Prévu le ${dateFr(isoDate(date))}')),
            ),
          ]),
        ),
        actions: [TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))), FilledButton(onPressed: product == null ? null : () => Navigator.pop(c, true), child: Text(tr('Créer')))],
      ),
    ),
  );
  if (ok != true || product == null || toDouble(q.text) <= 0) return;
  final bom = activeBom('${product!['id']}')!;
  final n = toDouble(q.text);
  final ratio = n / (toDouble(bom['quantity']) <= 0 ? 1 : toDouble(bom['quantity']));
  try {
    final o = await sync.save('mrp_orders', {
      'id': newId(),
      'product_id': product!['id'],
      'bom_id': bom['id'],
      'quantity': n,
      'planned_date': isoDate(date),
      'status': 'draft',
      'labour_minutes': (toDouble(bom['labour_minutes']) * ratio).round(),
      'components': [
        for (final l in (bom['lines'] as List? ?? []))
          {
            'product_id': l['product_id'],
            'description': LocalDb.get('products', l['product_id'] as String?)?['name'],
            'planned': double.parse((toDouble(l['quantity']) * (1 + toDouble(l['loss_percent']) / 100) * ratio).toStringAsFixed(4)),
          },
      ],
    });
    if (context.mounted) openProductionOrder(context, '${o['id']}');
  } catch (e) {
    if (context.mounted) toast(context, errorText(e), error: true);
  }
}

class ProductionOrderScreen extends StatelessWidget {
  const ProductionOrderScreen({super.key, required this.id});

  final String id;

  Future<void> _set(BuildContext context, Map<String, dynamic> data) async {
    try {
      await context.read<SyncEngine>().save('mrp_orders', {'id': id, ...data});
    } catch (e) {
      if (context.mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _finish(BuildContext context, Map<String, dynamic> o) async {
    final comps = [for (final c in (o['components'] as List? ?? [])) asMap(c)];
    final produced = TextEditingController(text: qty(toDouble(o['produced_qty'] ?? o['quantity'])));
    final minutes = TextEditingController(text: '${o['labour_minutes'] ?? 0}');
    final ctrls = {for (final c in comps) '${c['product_id']}': TextEditingController(text: qty(toDouble(c['consumed'] ?? c['planned'])))};
    var touched = false;
    final ok = await showFormDialog<bool>(context, title: 'Terminer la fabrication', builder: (d) => StatefulBuilder(
        builder: (d, set) => SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
            Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              TextField(
                controller: produced,
                autofocus: true,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                decoration: InputDecoration(labelText: tr('Quantité réellement produite'), helperText: tr('Prévue : ${qty(toDouble(o['quantity']))}')),
                onChanged: (_) {
                  // Tant que l'utilisateur n'a pas corrigé les consommations, elles suivent la quantité produite.
                  if (touched) return;
                  final r = toDouble(produced.text) / (toDouble(o['quantity']) <= 0 ? 1 : toDouble(o['quantity']));
                  set(() {
                    for (final c in comps) {
                      ctrls['${c['product_id']}']!.text = qty(double.parse((toDouble(c['planned']) * r).toStringAsFixed(3)));
                    }
                  });
                },
              ),
              const SizedBox(height: 12),
              Text(tr('Composants consommés'), style: TextStyle(fontWeight: FontWeight.w700)),
              for (final c in comps)
                Padding(
                  padding: const EdgeInsets.only(top: 6),
                  child: Row(children: [
                    Expanded(child: Text(tr('${c['description'] ?? LocalDb.get('products', c['product_id'] as String?)?['name'] ?? ''}'))),
                    SizedBox(
                      width: 120,
                      child: TextField(
                        controller: ctrls['${c['product_id']}'],
                        keyboardType: const TextInputType.numberWithOptions(decimal: true),
                        decoration: InputDecoration(isDense: true, suffixText: trn('${LocalDb.get('products', c['product_id'] as String?)?['unit'] ?? ''}')),
                        onChanged: (_) => touched = true,
                      ),
                    ),
                  ]),
                ),
              const SizedBox(height: 12),
              TextField(controller: minutes, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Temps de travail (minutes)'))),
              const SizedBox(height: 8),
              Text(tr('Les composants sortent du stock et le produit fini y entre à son coût de revient.'), style: TextStyle(fontSize: 12, color: Theme.of(d).hintColor)),
            ]),
            const SizedBox(height: 20),
            Align(
              alignment: Alignment.centerRight,
              child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
                TextButton(onPressed: () => Navigator.pop(d, false), child: Text(tr('Annuler'))),
                FilledButton(style: FilledButton.styleFrom(backgroundColor: YColors.success), onPressed: () => Navigator.pop(d, true), child: Text(tr('Terminer'))),
              ]),
            ),
          ]),
        ),
      ));
    if (ok != true || toDouble(produced.text) <= 0 || !context.mounted) return;
    await _set(context, {
      'status': 'done',
      'produced_qty': toDouble(produced.text),
      'labour_minutes': toDouble(minutes.text).round(),
      'components': [for (final c in comps) {'product_id': c['product_id'], 'consumed': toDouble(ctrls['${c['product_id']}']!.text)}],
    });
  }

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    final manage = s.can('production.manage');
    final operate = s.can('production.operate') || manage;
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('mrp_orders'),
      builder: (context, _, _) {
        final o = LocalDb.get('mrp_orders', id);
        if (o == null) return Scaffold(appBar: AppBar(), body: const SizedBox());
        final p = LocalDb.get('products', o['product_id'] as String?);
        final st = '${o['status']}';
        final stock = StockCalc.totals();
        final comps = [for (final c in (o['components'] as List? ?? [])) asMap(c)];
        final rate = labourRate(s);
        final planCost = comps.fold<double>(0, (t, c) => t + toDouble(c['planned']) * componentCost(LocalDb.get('products', c['product_id'] as String?), rate)) +
            toDouble(o['labour_minutes']) / 60 * rate;
        final cur = s.currency;
        return Scaffold(
          appBar: AppBar(title: Text(tr('${o['number'] ?? 'Ordre de fabrication'}')), actions: [
            if (manage && ['draft', 'confirmed', 'in_progress'].contains(st))
              PopupMenuButton<String>(
                onSelected: (v) async {
                  if (v == 'cancel' && await confirm(context, tr('Annuler l\'ordre de fabrication'), tr('Aucun mouvement de stock n\'a encore eu lieu.'), ok: tr('Annuler l\'ordre'), danger: true)) {
                    if (context.mounted) await _set(context, {'status': 'cancelled'});
                  }
                  if (v == 'draft' && context.mounted) await _set(context, {'status': 'draft'});
                },
                itemBuilder: (_) => [
                  if (st == 'confirmed') PopupMenuItem(value: 'draft', child: Text(tr('Repasser en brouillon'))),
                  PopupMenuItem(value: 'cancel', child: Text(tr('Annuler'), style: TextStyle(color: YColors.danger))),
                ],
              ),
          ]),
          body: ListView(padding: const EdgeInsets.all(16), children: [
            Wrap(spacing: 12, runSpacing: 12, crossAxisAlignment: WrapCrossAlignment.center, children: [
              Text(tr('${p?['name'] ?? ''}'), style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800)),
              StatusChip(mrpStatus[st] ?? st, color: mrpColor(st)),
            ]),
            const SizedBox(height: 6),
            Text(
              tr([
                '${qty(toDouble(o['quantity']))} ${p?['unit'] ?? ''} prévus',
                if (o['produced_qty'] != null) '${qty(toDouble(o['produced_qty']))} produits',
                if (o['planned_date'] != null) 'pour le ${dateFr(o['planned_date'])}',
                if (o['user_name'] != null) 'par ${o['user_name']}',
              ].join(' · ')),
              style: TextStyle(color: Theme.of(context).hintColor),
            ),
            const SizedBox(height: 16),
            Wrap(spacing: 8, runSpacing: 8, children: [
              if (manage && st == 'draft') FilledButton.icon(onPressed: () => _set(context, {'status': 'confirmed'}), icon: const Icon(Icons.check), label: Text(tr('Confirmer'))),
              if (operate && st == 'confirmed') FilledButton.icon(onPressed: () => _set(context, {'status': 'in_progress'}), icon: const Icon(Icons.play_arrow), label: Text(tr('Démarrer'))),
              if (operate && (st == 'in_progress' || st == 'confirmed'))
                FilledButton.icon(style: FilledButton.styleFrom(backgroundColor: YColors.success), onPressed: () => _finish(context, o), icon: const Icon(Icons.flag_outlined), label: Text(tr('Terminer'))),
              if (st == 'done' && p != null && s.hasModule('etiquettes') && (s.can('etiquettes.print') || s.can('etiquettes.manage')))
                OutlinedButton.icon(
                  onPressed: () => openLabels(context, [LabelItem(Map<String, dynamic>.from(p), count: toDouble(o['produced_qty']).ceil().clamp(1, 999))]),
                  icon: const Icon(Icons.qr_code_2),
                  label: Text(tr('Étiquettes')),
                ),
              if (manage && st == 'draft')
                OutlinedButton.icon(
                  onPressed: () async {
                    final q = TextEditingController(text: qty(toDouble(o['quantity'])));
                    final ok = await showDialog<bool>(
                      context: context,
                      builder: (d) => YAlertDialog(
                        title: Text(tr('Quantité à fabriquer')),
                        content: TextField(controller: q, autofocus: true, keyboardType: const TextInputType.numberWithOptions(decimal: true)),
                        actions: [TextButton(onPressed: () => Navigator.pop(d, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(d, true), child: Text(tr('OK')))],
                      ),
                    );
                    if (ok == true && toDouble(q.text) > 0 && context.mounted) await _set(context, {'quantity': toDouble(q.text)});
                  },
                  icon: const Icon(Icons.edit_outlined),
                  label: Text(tr('Modifier la quantité')),
                ),
            ]),
            const SizedBox(height: 16),
            SectionCard(
              title: tr('Composants'),
              child: Column(children: [
                for (final c in comps)
                  Builder(builder: (context) {
                    final cp = LocalDb.get('products', c['product_id'] as String?);
                    final need = toDouble(c['consumed'] ?? c['planned']);
                    final have = stock['${c['product_id']}'] ?? 0;
                    final short = st != 'done' && cp?['track_stock'] == true && have + 0.0001 < need;
                    return ListTile(
                      contentPadding: EdgeInsets.zero,
                      leading: Icon(short ? Icons.warning_amber_rounded : Icons.inventory_2_outlined, color: short ? YColors.danger : YColors.muted),
                      title: Text(tr('${c['description'] ?? cp?['name'] ?? ''}')),
                      subtitle: Text(tr(st == 'done'
                          ? 'Prévu ${qty(toDouble(c['planned']))} · consommé ${qty(toDouble(c['consumed']))} ${cp?['unit'] ?? ''}'
                          : 'En stock : ${qty(have)} ${cp?['unit'] ?? ''}${short ? ' — il manque ${qty(need - have)}' : ''}')),
                      trailing: Text(tr('${qty(need)} ${cp?['unit'] ?? ''}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                    );
                  }),
              ]),
            ),
            const SizedBox(height: 12),
            SectionCard(
              title: tr('Coût de revient'),
              child: st == 'done'
                  ? Column(children: [
                      _kv('Matières', money(toDouble(o['material_cost']), cur)),
                      _kv('Main-d\'œuvre et frais', money(toDouble(o['labour_cost']), cur)),
                      _kv('Coût unitaire', money(toDouble(o['unit_cost']), cur), bold: true),
                      if (toDouble(p?['sale_price']) > 0)
                        _kv('Marge sur prix de vente HT', '${money(toDouble(p?['sale_price']) - toDouble(o['unit_cost']), cur)} (${((1 - toDouble(o['unit_cost']) / toDouble(p?['sale_price'])) * 100).toStringAsFixed(1)} %)'),
                      _kv('Terminé le', dateTimeFr(o['done_at'])),
                    ])
                  : Column(children: [
                      _kv('Estimation (matières + main-d\'œuvre)', money(planCost, cur)),
                      _kv('Soit par unité', money(planCost / (toDouble(o['quantity']) <= 0 ? 1 : toDouble(o['quantity'])), cur), bold: true),
                      _kv('Temps de travail prévu', '${o['labour_minutes'] ?? 0} min'),
                    ]),
            ),
          ]),
        );
      },
    );
  }
}

Widget _kv(String k, String v, {bool bold = false}) => Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(children: [Expanded(child: Text(tr(k))), Text(tr(v), style: TextStyle(fontWeight: bold ? FontWeight.w800 : FontWeight.w600))]),
    );

// ======================================================================
// Nomenclatures & recettes
// ======================================================================

class _BomsTab extends StatefulWidget {
  const _BomsTab();

  @override
  State<_BomsTab> createState() => _BomsTabState();
}

class _BomsTabState extends State<_BomsTab> {
  String _q = '';
  String _status = '';
  final Map<String, String?> _f = {};
  int _gen = 0;

  static bool _statusMatch(Map<String, dynamic> b, String st) => switch (st) {
        'active' => b['active'] != false,
        'inactive' => b['active'] == false,
        _ => true,
      };

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    final cur = s.currency;
    final rate = labourRate(s);
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('mrp_boms'),
      builder: (context, _, _) {
        final all = LocalDb.all('mrp_boms');
        final base = all
            .where((b) => _q.isEmpty || '${b['name']}'.toLowerCase().contains(_q))
            .where((b) => _f['kind'] == null || (b['kind'] == 'kit' ? 'kit' : 'make') == _f['kind'])
            .toList();
        const statuses = {'': 'Toutes', 'active': 'Actives', 'inactive': 'Inactives'};
        final rows = base.where((b) => _statusMatch(b, _status)).toList()
          ..sort((a, b) => '${a['active'] == false}${a['name']}'.toLowerCase().compareTo('${b['active'] == false}${b['name']}'.toLowerCase()));
        return Column(children: [
          const SizedBox(height: 12),
          FilterBar(
            key: ValueKey(_gen),
            onSearch: (v) => setState(() => _q = v.toLowerCase()),
            search: _q,
            searchHint: 'Rechercher une nomenclature…',
            statuses: statuses,
            status: _status,
            onStatus: (v) => setState(() => _status = v),
            statusCounts: {for (final k in statuses.keys) k: base.where((b) => _statusMatch(b, k)).length},
            filters: const [FilterSpec('kind', 'Type', {'make': 'Fabrication', 'kit': 'Recette vendue'}, icon: Icons.category_outlined)],
            values: _f,
            onFilter: (k, v) => setState(() => _f[k] = v),
            count: rows.length,
            countLabel: 'nomenclature',
            onReset: () => setState(() {
              _q = '';
              _status = '';
              _f.clear();
              _gen++;
            }),
            trailing: [
              if (s.can('production.manage'))
                FilledButton.tonalIcon(onPressed: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const BomEditor())), icon: const Icon(Icons.add), label: Text(tr('Nomenclature'))),
            ],
          ),
          Expanded(
            child: rows.isEmpty
                ? EmptyState(
                    icon: Icons.account_tree_outlined,
                    title: tr('Aucune nomenclature'),
                    message: tr('Une nomenclature dit ce qu\'il faut pour fabriquer un produit (atelier, boulangerie) ; une recette dit ce que consomme un plat vendu (restaurant).'),
                  )
                : ResponsiveList<Map<String, dynamic>>(
                    items: rows,
                    columns: const ['Produit', 'Type', 'Composants', 'Coût unitaire', 'Prix de vente HT', 'Coût / prix'],
                    cells: (b) {
                      final c = bomCost(b, rate);
                      return [
                        Text(tr('${b['name']}'), style: TextStyle(fontWeight: FontWeight.w600, decoration: b['active'] == false ? TextDecoration.lineThrough : null)),
                        StatusChip(b['kind'] == 'kit' ? 'Recette vendue' : 'Fabrication', color: b['kind'] == 'kit' ? YColors.warning : YColors.blue),
                        Text(tr('${(b['lines'] as List?)?.length ?? 0}')),
                        Text(tr(money(c.unit, cur))),
                        Text(tr(c.price > 0 ? money(c.price, cur) : '—')),
                        _CostRate(c.costRate),
                      ];
                    },
                    mobileTile: (b) {
                      final c = bomCost(b, rate);
                      return ListTile(
                        contentPadding: EdgeInsets.zero,
                        leading: Icon(b['kind'] == 'kit' ? Icons.restaurant_menu : Icons.account_tree_outlined),
                        title: Text(tr('${b['name']}')),
                        subtitle: Text(tr('${b['kind'] == 'kit' ? 'Recette vendue' : 'Fabrication'} · coût ${money(c.unit, cur)}')),
                        trailing: _CostRate(c.costRate),
                      );
                    },
                    onTap: (b) => Navigator.of(context).push(MaterialPageRoute(builder: (_) => BomEditor(id: '${b['id']}'))),
                  ),
          ),
        ]);
      },
    );
  }
}

/// Part du coût dans le prix de vente : vert jusqu'à 35 %, orange jusqu'à 60 %, rouge au-delà (ou à perte).
class _CostRate extends StatelessWidget {
  const _CostRate(this.rate);

  final double? rate;

  @override
  Widget build(BuildContext context) {
    if (rate == null) return const Text('—');
    final r = rate!;
    return StatusChip('${r.toStringAsFixed(0)} %', color: r <= 35 ? YColors.success : (r <= 60 ? YColors.warning : YColors.danger));
  }
}

class BomEditor extends StatefulWidget {
  const BomEditor({super.key, this.id});

  final String? id;

  @override
  State<BomEditor> createState() => _BomEditorState();
}

class _BomEditorState extends State<BomEditor> {
  late final Map<String, dynamic>? _orig = widget.id == null ? null : LocalDb.get('mrp_boms', widget.id!);
  late String? _product = _orig?['product_id'] as String?;
  late String _kind = '${_orig?['kind'] ?? 'manufacture'}';
  late bool _active = _orig?['active'] != false;
  late final _qty = TextEditingController(text: qty(toDouble(_orig?['quantity'] ?? 1)));
  late final _minutes = TextEditingController(text: '${_orig?['labour_minutes'] ?? 0}');
  late final _extra = TextEditingController(text: '${toDouble(_orig?['extra_cost']).round()}');
  late final _notes = TextEditingController(text: '${_orig?['notes'] ?? ''}');
  late final List<Map<String, dynamic>> _lines = [
    for (final l in (_orig?['lines'] as List? ?? []))
      {
        'product_id': l['product_id'],
        'q': TextEditingController(text: qty(toDouble(l['quantity']))),
        'loss': TextEditingController(text: toDouble(l['loss_percent']) == 0 ? '' : qty(toDouble(l['loss_percent']))),
      },
  ];

  Map<String, dynamic> get _draft => {
        'product_id': _product,
        'kind': _kind,
        'quantity': toDouble(_qty.text),
        'labour_minutes': toDouble(_minutes.text).round(),
        'extra_cost': toDouble(_extra.text),
        'lines': [
          for (final l in _lines)
            if (toDouble((l['q'] as TextEditingController).text) > 0) {'product_id': l['product_id'], 'quantity': toDouble((l['q'] as TextEditingController).text), 'loss_percent': toDouble((l['loss'] as TextEditingController).text)},
        ],
      };

  Future<void> _addLine() async {
    final p = await pickProduct(context, title: tr('Ajouter un composant'), where: (p) => p['id'] != _product && !_lines.any((l) => l['product_id'] == p['id']));
    if (p != null) setState(() => _lines.add({'product_id': p['id'], 'q': TextEditingController(text: '1'), 'loss': TextEditingController()}));
  }

  Future<void> _save() async {
    final d = _draft;
    if (_product == null || (d['lines'] as List).isEmpty || toDouble(_qty.text) <= 0) {
      toast(context, tr('Choisissez le produit, la quantité obtenue et au moins un composant.'), error: true);
      return;
    }
    try {
      await context.read<SyncEngine>().save('mrp_boms', {'id': widget.id ?? newId(), ...d, 'notes': _notes.text.trim().isEmpty ? null : _notes.text.trim(), 'active': _active, 'name': LocalDb.get('products', _product)?['name']});
      if (mounted) {
        toast(context, tr('Nomenclature enregistrée.'));
        Navigator.pop(context);
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    final manage = s.can('production.manage');
    final cur = s.currency;
    final rate = labourRate(s);
    final p = LocalDb.get('products', _product);
    final c = bomCost(_draft, rate);
    final unitLabel = '${p?['unit'] ?? 'unité(s)'}';
    return Scaffold(
      appBar: AppBar(title: Text(tr(widget.id == null ? 'Nouvelle nomenclature' : '${_orig?['name'] ?? 'Nomenclature'}')), actions: [
        if (manage && widget.id != null)
          IconButton(
            tooltip: tr('Supprimer'),
            onPressed: () async {
              if (!await confirm(context, tr('Supprimer la nomenclature'), tr('Les ordres déjà terminés ne sont pas touchés.'), ok: tr('Supprimer'), danger: true) || !context.mounted) return;
              try {
                await context.read<SyncEngine>().remove('mrp_boms', widget.id!);
                if (context.mounted) Navigator.pop(context);
              } catch (e) {
                if (context.mounted) toast(context, errorText(e), error: true);
              }
            },
            icon: const Icon(Icons.delete_outline),
          ),
        if (manage) Padding(padding: const EdgeInsets.only(right: 12), child: FilledButton(onPressed: _save, child: Text(tr('Enregistrer')))),
      ]),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        SectionCard(
          title: tr('Produit'),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            SegmentedButton<String>(
              segments: [
                ButtonSegment(value: 'manufacture', label: Text(tr('Fabrication')), icon: Icon(Icons.precision_manufacturing_outlined)),
                ButtonSegment(value: 'kit', label: Text(tr('Recette vendue')), icon: Icon(Icons.restaurant_menu)),
              ],
              selected: {_kind},
              onSelectionChanged: manage ? (v) => setState(() => _kind = v.first) : null,
            ),
            const SizedBox(height: 6),
            Text(
              tr(_kind == 'kit'
                  ? 'Chaque vente du produit (plat, kit, panier) fait sortir ses ingrédients du stock. Le produit lui-même n\'est pas suivi en stock.'
                  : 'Le produit est fabriqué à l\'avance par un ordre de fabrication : les composants sortent du stock, le produit fini y entre.'),
              style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor),
            ),
            const SizedBox(height: 12),
            OutlinedButton.icon(
              onPressed: manage && widget.id == null
                  ? () async {
                      final sel = await pickProduct(
                        context,
                        title: _kind == 'kit' ? 'Plat ou kit vendu' : 'Produit fabriqué',
                        where: (x) => _kind == 'kit' ? (x['type'] == 'service' || x['track_stock'] != true) : (x['type'] != 'service' && x['track_stock'] == true),
                      );
                      if (sel != null) setState(() => _product = '${sel['id']}');
                    }
                  : null,
              icon: Icon(_kind == 'kit' ? Icons.restaurant_menu : Icons.inventory_2_outlined),
              label: Text(tr(p == null ? 'Choisir le produit' : '${p['name']}')),
            ),
            const SizedBox(height: 12),
            Row(children: [
              Expanded(child: TextField(controller: _qty, enabled: manage, keyboardType: const TextInputType.numberWithOptions(decimal: true), onChanged: (_) => setState(() {}), decoration: InputDecoration(labelText: tr('Quantité obtenue'), suffixText: trn(unitLabel)))),
              const SizedBox(width: 12),
              Expanded(child: TextField(controller: _minutes, enabled: manage, keyboardType: TextInputType.number, onChanged: (_) => setState(() {}), decoration: InputDecoration(labelText: tr('Temps de travail'), suffixText: tr('min')))),
              const SizedBox(width: 12),
              Expanded(child: TextField(controller: _extra, enabled: manage, keyboardType: TextInputType.number, onChanged: (_) => setState(() {}), decoration: InputDecoration(labelText: tr('Frais (énergie, emballage)'), suffixText: trn(cur)))),
            ]),
            SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Nomenclature active')), subtitle: Text(tr('Une seule active par produit')), value: _active, onChanged: manage ? (v) => setState(() => _active = v) : null),
          ]),
        ),
        const SizedBox(height: 12),
        SectionCard(
          title: tr('Composants pour ${_qty.text.isEmpty ? '…' : _qty.text} $unitLabel'),
          trailing: manage ? TextButton.icon(onPressed: _addLine, icon: const Icon(Icons.add, size: 18), label: Text(tr('Composant'))) : null,
          child: Column(children: [
            if (_lines.isEmpty) Text(tr('Ajoutez les matières premières, ingrédients ou pièces.'), style: TextStyle(color: Theme.of(context).hintColor)),
            for (var i = 0; i < _lines.length; i++)
              Builder(builder: (context) {
                final l = _lines[i];
                final cp = LocalDb.get('products', l['product_id'] as String?);
                final unitCost = componentCost(cp, rate);
                final q = toDouble((l['q'] as TextEditingController).text) * (1 + toDouble((l['loss'] as TextEditingController).text) / 100);
                return Padding(
                  padding: const EdgeInsets.symmetric(vertical: 6),
                  child: Row(children: [
                    Expanded(
                      flex: 3,
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Text(tr('${cp?['name'] ?? '?'}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                        Text(tr('${money(unitCost, cur)} / ${cp?['unit'] ?? 'u'}${toDouble(cp?['purchase_price']) <= 0 && activeBom(l['product_id'] as String?) != null ? ' (fabriqué)' : ''}${unitCost <= 0 ? ' — coût inconnu' : ''}'),
                            style: TextStyle(fontSize: 12, color: unitCost <= 0 ? YColors.danger : Theme.of(context).hintColor)),
                      ]),
                    ),
                    SizedBox(width: 130, child: TextField(controller: l['q'] as TextEditingController, enabled: manage, keyboardType: const TextInputType.numberWithOptions(decimal: true), onChanged: (_) => setState(() {}), decoration: InputDecoration(isDense: true, labelText: tr('Qté'), suffixText: trn('${cp?['unit'] ?? ''}')))),
                    const SizedBox(width: 8),
                    SizedBox(width: 80, child: TextField(controller: l['loss'] as TextEditingController, enabled: manage, keyboardType: const TextInputType.numberWithOptions(decimal: true), onChanged: (_) => setState(() {}), decoration: InputDecoration(isDense: true, labelText: tr('Perte'), suffixText: '%'))),
                    SizedBox(width: 110, child: Text(tr(money(q * unitCost, cur)), textAlign: TextAlign.right, style: const TextStyle(fontWeight: FontWeight.w600))),
                    if (manage) IconButton(onPressed: () => setState(() => _lines.removeAt(i)), icon: const Icon(Icons.close)),
                  ]),
                );
              }),
          ]),
        ),
        const SizedBox(height: 12),
        SectionCard(
          title: tr('Coût de revient par $unitLabel'),
          child: Column(children: [
            _kv('Matières', money(c.material, cur)),
            _kv('Main-d\'œuvre${rate <= 0 ? ' (coût horaire non réglé)' : ' (${money(rate, cur)}/h)'}', money(c.labour, cur)),
            _kv('Frais', money(c.extra, cur)),
            _kv('Coût de revient', money(c.unit, cur), bold: true),
            if (c.price > 0) ...[
              const Divider(),
              _kv('Prix de vente HT', money(c.price, cur)),
              _kv('Marge', money(c.margin, cur), bold: true),
              Row(children: [
                Expanded(child: Text(tr('Coût de revient / prix de vente'))),
                _CostRate(c.costRate),
              ]),
            ],
          ]),
        ),
        const SizedBox(height: 12),
        SectionCard(title: tr('Notes (mode opératoire)'), child: TextField(controller: _notes, enabled: manage, maxLines: 4, decoration: InputDecoration(hintText: tr('Étapes, températures, temps de cuisson…')))),
        if (_kind == 'manufacture' && widget.id != null && s.can('production.manage')) ...[
          const SizedBox(height: 12),
          Align(
            alignment: Alignment.centerLeft,
            child: OutlinedButton.icon(onPressed: () => newProductionOrder(context, productId: _product), icon: const Icon(Icons.play_circle_outline), label: Text(tr('Lancer une fabrication'))),
          ),
        ],
      ]),
    );
  }
}

// ======================================================================
// Besoins
// ======================================================================

class _NeedsTab extends StatefulWidget {
  const _NeedsTab();

  @override
  State<_NeedsTab> createState() => _NeedsTabState();
}

class _NeedsTabState extends State<_NeedsTab> {
  List<Map<String, dynamic>>? _rows;
  Object? _error;
  String _q = '';
  String _status = '';
  int _gen = 0;

  static bool _statusMatch(Map<String, dynamic> r, String st) => switch (st) {
        'missing' => toDouble(r['missing']) > 0,
        'ok' => toDouble(r['missing']) <= 0,
        _ => true,
      };

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('production/needs');
      if (mounted) setState(() => (_rows = [for (final e in (r['data'] as List)) asMap(e)], _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_rows == null) return _error != null ? OnlineOnlyNotice(error: _error!, onRetry: _load) : const LoadingSkeleton();
    final cur = context.read<Session>().currency;
    final missing = _rows!.where((r) => toDouble(r['missing']) > 0).toList();
    const needStatuses = {'': 'Tous', 'missing': 'À acheter', 'ok': 'Couverts'};
    final base = _rows!.where((r) => _q.isEmpty || '${r['name']} ${(r['orders'] as List? ?? []).join(' ')}'.toLowerCase().contains(_q)).toList();
    final shown = base.where((r) => _statusMatch(r, _status)).toList();
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 8, 8),
        child: Row(children: [
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(
                tr(missing.isEmpty ? 'Le stock couvre tous les ordres confirmés et en cours.' : '${missing.length} composant(s) à acheter, environ ${money(missing.fold<double>(0, (t, r) => t + toDouble(r['cost'])), cur)}.'),
                style: TextStyle(fontWeight: FontWeight.w700, color: missing.isEmpty ? YColors.success : YColors.danger),
              ),
              if (missing.isNotEmpty && context.read<Session>().hasModule('achats'))
                Text(tr('Passez la commande depuis Achats › Réapprovisionnement.'), style: TextStyle(color: Theme.of(context).hintColor)),
            ]),
          ),
          IconButton(tooltip: tr('Actualiser'), onPressed: _load, icon: const Icon(Icons.refresh)),
        ]),
      ),
      if (_rows!.isNotEmpty)
        FilterBar(
          key: ValueKey(_gen),
          onSearch: (v) => setState(() => _q = v.toLowerCase()),
          search: _q,
          searchHint: 'Composant ou ordre…',
          statuses: needStatuses,
          status: _status,
          onStatus: (v) => setState(() => _status = v),
          statusCounts: {for (final k in needStatuses.keys) k: base.where((r) => _statusMatch(r, k)).length},
          count: shown.length,
          countLabel: 'composant',
          onReset: () => setState(() {
            _q = '';
            _status = '';
            _gen++;
          }),
        ),
      Expanded(
        child: _rows!.isEmpty
            ? EmptyState(icon: Icons.fact_check_outlined, title: tr('Aucun besoin'), message: tr('Les besoins viennent des ordres de fabrication confirmés ou en cours.'))
            : ResponsiveList<Map<String, dynamic>>(
                items: shown,
                columns: const ['Composant', 'Nécessaire', 'En stock', 'Manque', 'Ordres'],
                cells: (r) => [
                  Text(tr('${r['name']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                  Text(tr('${qty(toDouble(r['needed']))} ${r['unit'] ?? ''}')),
                  Text(tr('${qty(toDouble(r['stock']))} ${r['unit'] ?? ''}')),
                  toDouble(r['missing']) > 0 ? StatusChip('${qty(toDouble(r['missing']))} ${r['unit'] ?? ''}', color: YColors.danger) : StatusChip(tr('OK'), color: YColors.success),
                  Text(tr((r['orders'] as List).join(', ')), overflow: TextOverflow.ellipsis),
                ],
                mobileTile: (r) => ListTile(
                  contentPadding: EdgeInsets.zero,
                  title: Text(tr('${r['name']}')),
                  subtitle: Text(tr('Nécessaire ${qty(toDouble(r['needed']))} · stock ${qty(toDouble(r['stock']))}')),
                  trailing: toDouble(r['missing']) > 0 ? StatusChip('−${qty(toDouble(r['missing']))}', color: YColors.danger) : StatusChip(tr('OK'), color: YColors.success),
                ),
              ),
      ),
    ]);
  }
}
