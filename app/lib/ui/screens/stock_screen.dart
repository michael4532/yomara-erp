import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../../core/lots.dart';
import '../../core/local_db.dart';
import '../../core/stock_calc.dart';
import '../../core/sync_engine.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/filter_bar.dart';
import '../../i18n/i18n.dart';

const moveTypes = {
  'initial': 'Stock initial',
  'purchase': 'Achat',
  'sale': 'Vente',
  'return': 'Retour client',
  'adjustment': 'Ajustement',
  'transfer_in': 'Transfert entrant',
  'transfer_out': 'Transfert sortant',
  'loss': 'Perte / casse',
  'production': 'Fabrication',
  'consumption': 'Consommation',
  'consumption_return': 'Consommation annulée',
};

class StockScreen extends StatefulWidget {
  const StockScreen({super.key});

  @override
  State<StockScreen> createState() => _StockScreenState();
}

class _StockScreenState extends State<StockScreen> with SingleTickerProviderStateMixin {
  late final TabController _tabs = TabController(length: 3, vsync: this);
  String _q = '';
  String _status = '';
  Map<String, String?> _f = {};
  // Mouvements
  String _mq = '';
  String _mStatus = '';
  Map<String, String?> _mf = {};
  FilterPeriod? _mPeriod;
  // Entrepôts
  String _wq = '';

  static const _levelStatuses = {'': 'Tous', 'ok': 'Disponibles', 'alert': 'En alerte', 'out': 'En rupture'};
  static const _moveDirections = {'': 'Tous', 'in': 'Entrées', 'out': 'Sorties'};

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final canEdit = session.can('stock.manage');

    return Column(children: [
      PageHeader(
        title: tr('Stock'),
        actions: [
          if (canEdit) ...[
            OutlinedButton.icon(onPressed: () => _moveDialog(context, transfer: true), icon: const Icon(Icons.swap_horiz), label: Text(tr('Transfert'))),
            OutlinedButton.icon(onPressed: () => _inventoryDialog(context), icon: const Icon(Icons.fact_check_outlined), label: Text(tr('Inventaire'))),
            FilledButton.icon(onPressed: () => _moveDialog(context), icon: const Icon(Icons.add), label: Text(tr('Mouvement'))),
          ],
        ],
      ),
      TabBar(controller: _tabs, isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [
        Tab(text: tr('Niveaux de stock')),
        Tab(text: tr('Mouvements')),
        Tab(text: tr('Entrepôts')),
      ]),
      const SizedBox(height: 12),
      Expanded(
        child: TabBarView(controller: _tabs, children: [
          _levels(context, session),
          _moves(context),
          _warehouses(context, canEdit),
        ]),
      ),
    ]);
  }

  Widget _levels(BuildContext context, Session session) {
    final pad = MediaQuery.of(context).size.width < 700 ? 16.0 : 24.0;
    return Column(children: [
      Expanded(
        child: ValueListenableBuilder(
          valueListenable: LocalDb.listen('stock_moves'),
          builder: (context, _, _) {
            final levels = StockCalc.levels();
            final warehouses = {for (final w in LocalDb.all('warehouses')) w['id']: w['name']};
            final cats = {for (final c in LocalDb.all('product_categories')) '${c['id']}': '${c['name']}'};
            double sumOf(Map p) => (levels[p['id']] ?? {}).values.fold<double>(0, (a, b) => a + b);
            bool statusOk(Map<String, dynamic> p, String st) {
              final total = sumOf(p);
              return switch (st) {
                'ok' => total > toDouble(p['min_stock']),
                'alert' => total <= toDouble(p['min_stock']),
                'out' => total <= 0,
                _ => true,
              };
            }

            final stocked = LocalDb.all('products').where((p) => p['type'] != 'service' && p['track_stock'] != false).toList();
            final base = stocked.where((p) {
              if (_q.isNotEmpty && !'${p['name']} ${p['sku'] ?? ''}'.toLowerCase().contains(_q)) return false;
              final wh = _f['warehouse'], c = _f['category'], u = _f['unit'];
              if (wh != null && ((levels[p['id']] ?? {}).entries.where((e) => e.key == wh && e.value != 0).isEmpty)) return false;
              if (c != null && '${p['category_id']}' != c) return false;
              if (u != null && '${p['unit'] ?? ''}' != u) return false;
              return true;
            }).toList();
            final products = base.where((p) => statusOk(p, _status)).toList()
              ..sort((a, b) => '${a['name']}'.toLowerCase().compareTo('${b['name']}'.toLowerCase()));
            final bar = FilterBar(
              padding: EdgeInsets.fromLTRB(pad, 0, pad, 12),
              search: _q,
              searchHint: 'Produit ou référence…',
              onSearch: (v) => setState(() => _q = v.toLowerCase()),
              statuses: _levelStatuses,
              status: _status,
              onStatus: (v) => setState(() => _status = v),
              statusCounts: {for (final k in _levelStatuses.keys) k: base.where((p) => statusOk(p, k)).length},
              filters: [
                if (warehouses.length > 1) FilterSpec('warehouse', 'Entrepôt', {for (final e in warehouses.entries) '${e.key}': '${e.value}'}, icon: Icons.warehouse_outlined),
                if (cats.isNotEmpty) FilterSpec('category', 'Catégorie', filterOptions(stocked.where((p) => cats.containsKey('${p['category_id']}')), 'category_id', label: (p) => cats['${p['category_id']}']!), icon: Icons.category_outlined),
                FilterSpec('unit', 'Unité', filterOptions(stocked, 'unit'), icon: Icons.straighten_outlined),
              ],
              values: _f,
              onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
              count: products.length,
              countLabel: 'article',
              onReset: () => setState(() => (_q = '', _status = '', _f = {})),
            );

            final tracked = stocked;
            double totalOf(Map p) => (levels[p['id']] ?? {}).values.fold<double>(0, (a, b) => a + b);
            final value = tracked.fold<double>(0, (a, p) => a + (totalOf(p) > 0 ? totalOf(p) * toDouble(p['purchase_price']) : 0));
            final out = tracked.where((p) => totalOf(p) <= 0).length;
            final lowN = tracked.where((p) => totalOf(p) > 0 && totalOf(p) <= toDouble(p['min_stock'])).length;
            final hint = Theme.of(context).hintColor;
            const tab = TextStyle(fontFeatures: [FontFeature.tabularFigures()]);

            return Column(children: [
              StatStrip(padding: EdgeInsets.fromLTRB(pad, 0, pad, 14), tiles: [
                StatTile(label: tr('Articles suivis'), value: '${tracked.length}', detail: tr('${warehouses.length} entrepôt(s)'), icon: Icons.inventory_2_outlined, color: const Color(0xFF0D9488)),
                StatTile(label: tr('Valeur du stock (achat)'), value: money(value, session.currency), icon: Icons.account_balance_outlined, color: const Color(0xFF4F46E5)),
                StatTile(label: tr('Sous le seuil'), value: '$lowN', detail: tr('À réapprovisionner'), icon: Icons.trending_down, color: YColors.warning, onTap: () => setState(() => _status = 'alert')),
                StatTile(label: tr('En rupture'), value: '$out', detail: out == 0 ? 'Aucune rupture' : 'Stock à zéro', icon: Icons.remove_shopping_cart_outlined, color: YColors.danger, onTap: () => setState(() => _status = 'out')),
              ]),
              bar,
              Expanded(child: products.isEmpty
                  ? EmptyState(icon: Icons.inventory_2_outlined, title: tr(stocked.isEmpty ? 'Aucun produit stocké' : 'Aucun résultat'))
                  : ResponsiveList<Map<String, dynamic>>(
              items: products,
              columns: const ['Produit', 'Quantité', 'Seuil', 'Répartition', 'Valeur (achat)'],
              cells: (p) {
                final byWh = levels[p['id']] ?? {};
                final total = byWh.values.fold<double>(0, (a, b) => a + b);
                final low = total <= toDouble(p['min_stock']);
                final c = total <= 0 ? YColors.danger : (low ? YColors.warning : YColors.success);
                return [
                  Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(tr('${p['name']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                    if ((p['sku'] ?? '').toString().isNotEmpty) Text(tr('Réf. ${p['sku']}'), style: TextStyle(color: hint, fontSize: 12)),
                  ]),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                    decoration: BoxDecoration(color: c.withValues(alpha: .10), borderRadius: BorderRadius.circular(8)),
                    child: Text(tr('${qty(total)} ${p['unit'] ?? ''}'), style: tab.copyWith(color: Color.lerp(c, Colors.black, .2), fontWeight: FontWeight.w700)),
                  ),
                  Text(tr(qty(toDouble(p['min_stock']))), style: tab.copyWith(color: hint)),
                  Text(tr(byWh.entries.map((e) => '${warehouses[e.key] ?? 'Défaut'} : ${qty(e.value)}').join(' · ')), style: TextStyle(color: hint, fontSize: 13)),
                  Text(tr(money(total > 0 ? total * toDouble(p['purchase_price']) : 0, session.currency)), style: tab.copyWith(fontWeight: FontWeight.w600)),
                ];
              },
              mobileTile: (p) {
                final total = (levels[p['id']] ?? {}).values.fold<double>(0, (a, b) => a + b);
                final low = total <= toDouble(p['min_stock']);
                return Row(children: [
                  Expanded(child: Text(tr('${p['name']}'), style: const TextStyle(fontWeight: FontWeight.w600))),
                  Text(tr('${qty(total)} ${p['unit'] ?? ''}'), style: TextStyle(color: low ? YColors.danger : null, fontWeight: FontWeight.w700)),
                ]);
              },
            )),
            ]);
          },
        ),
      ),
    ]);
  }

  Widget _moves(BuildContext context) {
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('stock_moves'),
      builder: (context, _, _) {
        final products = {for (final p in LocalDb.all('products')) p['id']: p};
        final warehouses = {for (final w in LocalDb.all('warehouses')) w['id']: w['name']};
        final all = LocalDb.all('stock_moves')
          ..sort((a, b) => '${b['date']}${b['created_at'] ?? ''}'.compareTo('${a['date']}${a['created_at'] ?? ''}'));
        bool dirOk(Map<String, dynamic> m, String d) => d.isEmpty || (d == 'in' ? toDouble(m['quantity']) > 0 : toDouble(m['quantity']) < 0);
        final base = all.where((m) {
          if (_mq.isNotEmpty && !'${products[m['product_id']]?['name'] ?? ''} ${products[m['product_id']]?['sku'] ?? ''} ${m['reference'] ?? ''}'.toLowerCase().contains(_mq)) return false;
          final t = _mf['type'], wh = _mf['warehouse'], pr = _mf['product'];
          if (t != null && '${m['type']}' != t) return false;
          if (wh != null && '${m['warehouse_id']}' != wh) return false;
          if (pr != null && '${m['product_id']}' != pr) return false;
          if (_mPeriod != null && !_mPeriod!.contains(m['date'])) return false;
          return true;
        }).toList();
        final moves = base.where((m) => dirOk(m, _mStatus)).toList();
        final pad = MediaQuery.of(context).size.width < 700 ? 16.0 : 24.0;
        final bar = FilterBar(
          padding: EdgeInsets.fromLTRB(pad, 0, pad, 12),
          search: _mq,
          searchHint: 'Produit, référence…',
          onSearch: (v) => setState(() => _mq = v.toLowerCase()),
          statuses: _moveDirections,
          status: _mStatus,
          onStatus: (v) => setState(() => _mStatus = v),
          statusCounts: {for (final k in _moveDirections.keys) k: base.where((m) => dirOk(m, k)).length},
          filters: [
            FilterSpec('type', 'Type', {for (final t in all.map((m) => '${m['type']}').toSet()) t: moveTypes[t] ?? t}, icon: Icons.swap_vert),
            if (warehouses.length > 1) FilterSpec('warehouse', 'Entrepôt', {for (final e in warehouses.entries) '${e.key}': '${e.value}'}, icon: Icons.warehouse_outlined),
            FilterSpec('product', 'Produit', filterOptions(all.where((m) => products.containsKey(m['product_id'])), 'product_id', label: (m) => '${products[m['product_id']]?['name']}'), icon: Icons.inventory_2_outlined),
          ],
          values: _mf,
          onFilter: (k, v) => setState(() => _mf = {..._mf, k: v}),
          showPeriod: true,
          period: _mPeriod,
          onPeriod: (v) => setState(() => _mPeriod = v),
          count: moves.length,
          countLabel: 'mouvement',
          onReset: () => setState(() => (_mq = '', _mStatus = '', _mf = {}, _mPeriod = null)),
        );
        if (moves.isEmpty) return Column(children: [bar, Expanded(child: EmptyState(icon: Icons.swap_vert, title: tr(all.isEmpty ? 'Aucun mouvement' : 'Aucun résultat')))]);
        return Column(children: [
          bar,
          Expanded(
            child: ResponsiveList<Map<String, dynamic>>(
          items: moves.take(500).toList(),
          columns: const ['Date', 'Produit', 'Type', 'Entrepôt', 'Quantité', 'Référence'],
          cells: (m) => [
            Text(tr(dateFr(m['date']))),
            Text(tr('${products[m['product_id']]?['name'] ?? '—'}')),
            Text(tr(moveTypes[m['type']] ?? '${m['type']}')),
            Text(tr('${warehouses[m['warehouse_id']] ?? '—'}')),
            Text(tr(toDouble(m['quantity']) > 0 ? '+${qty(toDouble(m['quantity']))}' : qty(toDouble(m['quantity']))),
                style: TextStyle(color: toDouble(m['quantity']) > 0 ? YColors.success : YColors.danger, fontWeight: FontWeight.w600)),
            Text(tr('${m['reference'] ?? ''}')),
          ],
          mobileTile: (m) => Row(children: [
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(tr('${products[m['product_id']]?['name'] ?? '—'}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                Text(tr('${dateFr(m['date'])} · ${moveTypes[m['type']] ?? m['type']}'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 13)),
              ]),
            ),
            Text(tr(toDouble(m['quantity']) > 0 ? '+${qty(toDouble(m['quantity']))}' : qty(toDouble(m['quantity']))),
                style: TextStyle(color: toDouble(m['quantity']) > 0 ? YColors.success : YColors.danger, fontWeight: FontWeight.w700)),
          ]),
            ),
          ),
        ]);
      },
    );
  }

  Widget _warehouses(BuildContext context, bool canEdit) {
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('warehouses'),
      builder: (context, _, _) {
        final all = LocalDb.all('warehouses');
        final items = all.where((w) => _wq.isEmpty || '${w['name']} ${w['code'] ?? ''} ${w['address'] ?? ''}'.toLowerCase().contains(_wq)).toList();
        final pad = MediaQuery.of(context).size.width < 700 ? 16.0 : 24.0;
        return ListView(padding: EdgeInsets.symmetric(horizontal: pad), children: [
          FilterBar(
            padding: const EdgeInsets.only(bottom: 12),
            search: _wq,
            searchHint: 'Nom, code ou adresse…',
            onSearch: (v) => setState(() => _wq = v.toLowerCase()),
            count: items.length,
            countLabel: 'entrepôt',
            onReset: () => setState(() => _wq = ''),
            trailing: [
              if (canEdit) TextButton.icon(onPressed: () => _warehouseDialog(context), icon: const Icon(Icons.add), label: Text(tr('Ajouter un entrepôt'))),
            ],
          ),
          if (all.isEmpty)
            EmptyState(icon: Icons.warehouse_outlined, title: tr('Aucun entrepôt'), message: tr('Un « Entrepôt principal » sera créé automatiquement à la première vente.'))
          else if (items.isEmpty)
            EmptyState(icon: Icons.search_off, title: tr('Aucun résultat')),
          for (final w in items)
            Card(
              margin: const EdgeInsets.only(bottom: 8),
              child: ListTile(
                leading: const DialogIconBadge(icon: Icon(Icons.warehouse_outlined), size: 38),
                title: Text(tr('${w['name']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                subtitle: Text(tr([w['code'], w['address']].where((e) => e != null).join(' · '))),
                trailing: w['is_default'] == true ? StatusChip(tr('Par défaut'), color: YColors.blue) : null,
                onTap: canEdit ? () => _warehouseDialog(context, w) : null,
              ),
            ),
        ]);
      },
    );
  }

  Future<void> _warehouseDialog(BuildContext context, [Map<String, dynamic>? w]) async {
    final name = TextEditingController(text: '${w?['name'] ?? ''}');
    final code = TextEditingController(text: '${w?['code'] ?? ''}');
    final address = TextEditingController(text: '${w?['address'] ?? ''}');
    var isDefault = (w?['is_default'] as bool?) ?? LocalDb.all('warehouses').isEmpty;
    final sync = context.read<SyncEngine>();
    await showFormDialog(context, title: w == null ? 'Nouvel entrepôt' : 'Modifier l\'entrepôt', builder: (c) {
      return StatefulBuilder(builder: (c, setS) {
        return ListView(padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), shrinkWrap: true, children: [
          Field(label: tr('Nom'), child: TextField(controller: name, autofocus: true)),
          Field(label: tr('Code'), child: TextField(controller: code)),
          Field(label: tr('Adresse'), child: TextField(controller: address)),
          SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Entrepôt par défaut')), value: isDefault, onChanged: (v) => setS(() => isDefault = v)),
          Align(
            alignment: Alignment.centerRight,
            child: FilledButton(
              onPressed: () async {
                if (name.text.trim().isEmpty) return;
                await sync.save('warehouses', {
                  if (w != null) 'id': w['id'],
                  'name': name.text.trim(),
                  'code': code.text.trim().isEmpty ? null : code.text.trim(),
                  'address': address.text.trim().isEmpty ? null : address.text.trim(),
                  'is_default': isDefault,
                });
                if (c.mounted) Navigator.pop(c);
              },
              child: Text(tr('Enregistrer')),
            ),
          ),
        ]);
      });
    });
  }

  Future<String?> _defaultWarehouse(SyncEngine sync) async {
    final list = LocalDb.all('warehouses');
    if (list.isNotEmpty) {
      return (list.firstWhere((w) => w['is_default'] == true, orElse: () => list.first))['id'] as String;
    }
    final w = await sync.save('warehouses', {'name': 'Entrepôt principal', 'code': 'PRINC', 'is_default': true});
    return w['id'] as String;
  }

  /// Mouvement manuel ou transfert (deux mouvements liés) — fonctionne hors ligne.
  Future<void> _moveDialog(BuildContext context, {bool transfer = false}) async {
    final sync = context.read<SyncEngine>();
    final defaultWh = await _defaultWarehouse(sync);
    if (!context.mounted) return;
    final products = LocalDb.all('products').where((p) => p['type'] != 'service' && p['track_stock'] != false).toList()
      ..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));
    if (products.isEmpty) {
      toast(context, tr('Créez d\'abord un produit stocké.'), error: true);
      return;
    }
    final warehouses = LocalDb.all('warehouses');
    String productId = products.first['id'];
    String type = 'initial';
    String from = defaultWh!;
    String to = warehouses.firstWhere((w) => w['id'] != from, orElse: () => {'id': from})['id'];
    final quantity = TextEditingController();
    final notes = TextEditingController();
    var direction = 1;
    final lotsOn = context.read<Session>().hasModule('lots');
    final lotNumber = TextEditingController();
    String? lotExpiry;
    String? lotId;

    await showFormDialog(context, title: transfer ? 'Transfert entre entrepôts' : 'Mouvement de stock', builder: (c) {
      return StatefulBuilder(builder: (c, setS) {
        return ListView(padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), shrinkWrap: true, children: [
          Field(
            label: tr('Produit'),
            child: DropdownButtonFormField<String>(
              isExpanded: true,
              initialValue: productId,
              items: [for (final p in products) DropdownMenuItem(value: p['id'] as String, child: Text(tr('${p['name']}'), overflow: TextOverflow.ellipsis))],
              onChanged: (v) => setS(() {
                productId = v!;
                lotId = null;
              }),
            ),
          ),
          if (!transfer) ...[
            Field(
              label: tr('Type'),
              child: DropdownButtonFormField<String>(
                initialValue: type,
                items: [
                  DropdownMenuItem(value: 'initial', child: Text(tr('Stock initial / entrée'))),
                  DropdownMenuItem(value: 'adjustment', child: Text(tr('Ajustement'))),
                  DropdownMenuItem(value: 'loss', child: Text(tr('Perte / casse'))),
                ],
                onChanged: (v) => setS(() {
                  type = v!;
                  direction = v == 'loss' ? -1 : (v == 'initial' ? 1 : direction);
                }),
              ),
            ),
            if (type == 'adjustment')
              Field(
                label: tr('Sens'),
                child: SegmentedButton<int>(
                  segments: [ButtonSegment(value: 1, label: Text(tr('Ajouter'))), ButtonSegment(value: -1, label: Text(tr('Retirer')))],
                  selected: {direction},
                  onSelectionChanged: (s) => setS(() => direction = s.first),
                ),
              ),
          ],
          if (warehouses.length > 1 || transfer)
            Field(
              label: transfer ? 'Depuis' : 'Entrepôt',
              child: DropdownButtonFormField<String>(
                initialValue: from,
                items: [for (final w in warehouses) DropdownMenuItem(value: w['id'] as String, child: Text(tr('${w['name']}')))],
                onChanged: (v) => setS(() => from = v!),
              ),
            ),
          if (transfer)
            Field(
              label: tr('Vers'),
              child: DropdownButtonFormField<String>(
                initialValue: to,
                items: [for (final w in warehouses) DropdownMenuItem(value: w['id'] as String, child: Text(tr('${w['name']}')))],
                onChanged: (v) => setS(() => to = v!),
              ),
            ),
          Field(
            label: tr('Quantité'),
            child: TextField(controller: quantity, autofocus: true, keyboardType: const TextInputType.numberWithOptions(decimal: true), inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9.,]'))]),
          ),
          if (lotsOn && isTracked(LocalDb.get('products', productId))) ...[
            if (!transfer && direction > 0) ...[
              Field(label: isSerial(LocalDb.get('products', productId)) ? 'N° de série (quantité 1)' : 'N° de lot (vide = sans lot)', child: TextField(controller: lotNumber)),
              if (LocalDb.get('products', productId)?['use_expiry'] == true)
                Field(
                  label: tr('Date de péremption'),
                  child: OutlinedButton(
                    onPressed: () async {
                      final d = await showDatePicker(context: c, initialDate: DateTime.now().add(const Duration(days: 90)), firstDate: DateTime(2000), lastDate: DateTime(2100));
                      if (d != null) setS(() => lotExpiry = isoDate(d));
                    },
                    child: Text(tr(lotExpiry == null ? 'Calculée selon la durée de vie' : dateFr(lotExpiry))),
                  ),
                ),
            ] else
              Field(
                label: tr('Lot'),
                child: DropdownButtonFormField<String?>(
                  key: ValueKey('lot-$productId-$from'),
                  initialValue: lotId,
                  isExpanded: true,
                  items: [
                    DropdownMenuItem<String?>(value: null, child: Text(tr('Sans lot'))),
                    for (final l in availableLots(productId, warehouseId: from))
                      DropdownMenuItem<String?>(value: l.id, child: Text(tr('${l.number} · ${qty(l.quantity)}${l.expiry != null ? ' · ${dateFr(l.lot['expiry_date'])}' : ''}${l.expired ? ' (périmé)' : ''}'), overflow: TextOverflow.ellipsis)),
                  ],
                  onChanged: (v) => setS(() => lotId = v),
                ),
              ),
          ],
          Field(label: tr('Note'), child: TextField(controller: notes)),
          Align(
            alignment: Alignment.centerRight,
            child: FilledButton(
              onPressed: () async {
                final q = toDouble(quantity.text);
                if (q <= 0) return;
                if (transfer && from == to) {
                  toast(c, tr('Choisissez deux entrepôts différents.'), error: true);
                  return;
                }
                final today = isoDate(DateTime.now());
                final note = notes.text.trim().isEmpty ? null : notes.text.trim();
                final tracked = lotsOn && isTracked(LocalDb.get('products', productId));
                final lotIn = tracked && !transfer && direction > 0 && lotNumber.text.trim().isNotEmpty ? {'lot_number': lotNumber.text.trim(), 'expiry_date': ?lotExpiry} : const <String, dynamic>{};
                final lotOut = tracked && lotId != null ? {'lot_id': lotId} : const <String, dynamic>{};
                if (tracked && isSerial(LocalDb.get('products', productId)) && (lotIn.isNotEmpty || lotOut.isNotEmpty) && q != 1) {
                  toast(c, tr('Un numéro de série correspond à une seule unité.'), error: true);
                  return;
                }
                if (transfer) {
                  final ref = 'TR-${DateTime.now().millisecondsSinceEpoch.toString().substring(5)}';
                  await sync.save('stock_moves', {'product_id': productId, 'warehouse_id': from, 'quantity': -q, 'type': 'transfer_out', 'reference': ref, 'date': today, 'notes': note, ...lotOut});
                  await sync.save('stock_moves', {'product_id': productId, 'warehouse_id': to, 'quantity': q, 'type': 'transfer_in', 'reference': ref, 'date': today, 'notes': note, ...lotOut});
                } else {
                  await sync.save('stock_moves', {'product_id': productId, 'warehouse_id': from, 'quantity': direction * q, 'type': type, 'date': today, 'notes': note, ...lotIn, if (direction < 0) ...lotOut});
                }
                if (c.mounted) Navigator.pop(c);
              },
              child: Text(tr('Enregistrer')),
            ),
          ),
        ]);
      });
    });
  }

  /// Inventaire : quantités comptées › mouvements d'ajustement pour les écarts.
  Future<void> _inventoryDialog(BuildContext context) async {
    final sync = context.read<SyncEngine>();
    final wh = await _defaultWarehouse(sync);
    if (!context.mounted) return;
    var warehouse = wh!;
    final products = LocalDb.all('products').where((p) => p['type'] != 'service' && p['track_stock'] != false).toList()
      ..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));
    final counts = <String, TextEditingController>{for (final p in products) p['id'] as String: TextEditingController()};

    await showFormDialog(context, title: tr('Inventaire'), builder: (c) {
      return StatefulBuilder(builder: (c, setS) {
        final levels = StockCalc.levels();
        return Column(mainAxisSize: MainAxisSize.min, children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(24, 0, 24, 8),
            child: Column(children: [
              Text(tr('Saisissez les quantités comptées. Les lignes vides sont ignorées.')),
              const SizedBox(height: 8),
              if (LocalDb.all('warehouses').length > 1)
                DropdownButtonFormField<String>(
                  initialValue: warehouse,
                  items: [for (final w in LocalDb.all('warehouses')) DropdownMenuItem(value: w['id'] as String, child: Text(tr('${w['name']}')))],
                  onChanged: (v) => setS(() => warehouse = v!),
                ),
            ]),
          ),
          Flexible(
            child: ListView(shrinkWrap: true, padding: const EdgeInsets.symmetric(horizontal: 24), children: [
              for (final p in products)
                Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: Row(children: [
                    Expanded(
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Text(tr('${p['name']}')),
                        Text(tr('Théorique : ${qty(levels[p['id']]?[warehouse] ?? 0)}'), style: TextStyle(fontSize: 12, color: Theme.of(c).hintColor)),
                      ]),
                    ),
                    SizedBox(
                      width: 110,
                      child: TextField(
                        controller: counts[p['id']],
                        textAlign: TextAlign.right,
                        decoration: InputDecoration(hintText: tr('Compté')),
                        keyboardType: const TextInputType.numberWithOptions(decimal: true),
                      ),
                    ),
                  ]),
                ),
            ]),
          ),
          Padding(
            padding: const EdgeInsets.all(24),
            child: Align(
              alignment: Alignment.centerRight,
              child: FilledButton(
                onPressed: () async {
                  final ref = 'INV-${isoDate(DateTime.now())}';
                  var n = 0;
                  for (final e in counts.entries) {
                    if (e.value.text.trim().isEmpty) continue;
                    final diff = toDouble(e.value.text) - (levels[e.key]?[warehouse] ?? 0);
                    if (diff.abs() < 0.0005) continue;
                    await sync.save('stock_moves', {'product_id': e.key, 'warehouse_id': warehouse, 'quantity': diff, 'type': 'adjustment', 'reference': ref, 'date': isoDate(DateTime.now()), 'notes': 'Inventaire'});
                    n++;
                  }
                  if (c.mounted) {
                    Navigator.pop(c);
                    toast(context, n == 0 ? 'Aucun écart.' : '$n ajustement(s) enregistré(s).');
                  }
                },
                child: Text(tr('Valider l\'inventaire')),
              ),
            ),
          ),
        ]);
      });
    });
  }
}
