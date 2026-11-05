import 'dart:typed_data';

import 'package:barcode/barcode.dart';
import 'package:flutter/material.dart';
import 'package:pdf/pdf.dart';
import 'package:printing/printing.dart';
import 'package:provider/provider.dart';

import '../../core/local_db.dart';
import '../../core/sync_engine.dart';
import '../../pdf/labels_pdf.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/filter_bar.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

/// Article à étiqueter : nombre d'étiquettes et, pour un article pesé, le poids du colis.
class LabelItem {
  LabelItem(this.product, {this.count = 1, this.weight});

  final Map<String, dynamic> product;
  int count;
  double? weight;
}

double ttcOf(Map<String, dynamic> p) => (toDouble(p['sale_price']) * (1 + toDouble(p['tax_rate']) / 100)).roundToDouble();

bool weighable(Map<String, dynamic> p) => (p['plu'] ?? '').toString().isNotEmpty && ['kg', 'kilo', 'kilogramme'].contains('${p['unit'] ?? ''}'.toLowerCase());

/// Ouvre l'écran d'impression avec une liste préremplie (réception, fabrication, fiche produit).
void openLabels(BuildContext context, List<LabelItem> items) => Navigator.of(context).push(labelsRoute(items));

MaterialPageRoute<void> labelsRoute(List<LabelItem> items) => MaterialPageRoute(
  builder: (_) => Scaffold(
    appBar: AppBar(title: Text(tr('Étiquettes'))),
    body: LabelsScreen(initial: items),
  ),
);

class LabelsScreen extends StatefulWidget {
  const LabelsScreen({super.key, this.initial});

  final List<LabelItem>? initial;

  @override
  State<LabelsScreen> createState() => _LabelsScreenState();
}

class _LabelsScreenState extends State<LabelsScreen> {
  late final List<LabelItem> _items = [...?widget.initial];
  late String _formatId = (LocalDb.meta.get('lb_format') as String?) ?? labelPresets.first.id;
  Set<String>? _fields;
  String? _style;
  int _skip = 0;
  late String _date = dateFr(isoDate(DateTime.now()));
  bool _busy = false;

  List<LabelFormat> get _formats => [...labelPresets, for (final t in LocalDb.all('lb_templates')) LabelFormat.fromMap(t)];

  LabelFormat get _format {
    final f = _formats.where((f) => f.id == _formatId).firstOrNull ?? labelPresets.first;
    return f.copyWith(style: _style, fields: _fields?.toList());
  }

  int get _total => _items.fold(0, (t, i) => t + i.count);

  List<LabelData> _labels(Session s) {
    final cats = {for (final c in LocalDb.all('product_categories')) '${c['id']}': '${c['name']}'};
    final out = <LabelData>[];
    for (final it in _items) {
      final p = it.product;
      final ttc = ttcOf(p);
      final unit = '${p['unit'] ?? ''}';
      final w = weighable(p) && (it.weight ?? 0) > 0 ? it.weight! : null;
      final d = LabelData(
        name: '${p['name']}',
        price: money(w != null ? (ttc * w).roundToDouble() : ttc, s.currency),
        unitPrice: w != null || ['kg', 'l', 'litre', 'm'].contains(unit.toLowerCase()) ? '${money(ttc, s.currency)} / $unit' : null,
        barcode: w != null ? weightBarcode('${p['plu']}', w) : (p['barcode'] as String?),
        sku: p['sku'] as String?,
        category: cats['${p['category_id']}'],
        company: '${s.company['name'] ?? ''}',
        date: _date.isEmpty ? null : _date,
        weight: w == null ? null : 'Poids net : ${w.toStringAsFixed(3).replaceAll('.', ',')} kg',
      );
      for (var k = 0; k < it.count; k++) {
        out.add(d);
      }
    }
    return out;
  }

  void _addProducts(Iterable<Map<String, dynamic>> products, {int count = 1}) {
    setState(() {
      for (final p in products) {
        final ex = _items.where((i) => i.product['id'] == p['id'] && i.weight == null).firstOrNull;
        if (ex != null && !weighable(p)) {
          ex.count += count;
        } else {
          _items.add(LabelItem(p, count: count));
        }
      }
    });
  }

  Future<void> _pickProducts() async {
    final all = LocalDb.all('products').where((p) => p['active'] != false).toList()..sort((a, b) => '${a['name']}'.toLowerCase().compareTo('${b['name']}'.toLowerCase()));
    final cats = {for (final c in LocalDb.all('product_categories')) '${c['id']}': '${c['name']}'};
    final printed = {for (final r in LocalDb.all('lb_prints')) '${r['product_id']}': toDouble(r['price'])};
    final picked = <String>{};
    var q = '';
    var f = <String, String?>{};
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => StatefulBuilder(
        builder: (c, set) {
          final rows = all.where((p) {
            if (q.isNotEmpty && !'${p['name']} ${p['sku'] ?? ''} ${p['barcode'] ?? ''}'.toLowerCase().contains(q)) return false;
            final cat = f['category'], bc = f['barcode'], ty = f['kind'], lb = f['label'];
            if (cat != null && '${p['category_id']}' != cat) return false;
            final hasCode = (p['barcode'] ?? '').toString().isNotEmpty;
            if (bc != null && (bc == 'with') != hasCode) return false;
            if (ty != null && (ty == 'weighed') != weighable(p)) return false;
            if (lb == 'never' && printed.containsKey(p['id'])) return false;
            if (lb == 'printed' && !printed.containsKey(p['id'])) return false;
            if (lb == 'changed' && !(printed.containsKey(p['id']) && (printed[p['id']]! - ttcOf(p)).abs() >= 1)) return false;
            return true;
          }).toList();
          return YAlertDialog(
            title: Text(tr('Ajouter des articles')),
            content: SizedBox(
              width: 480,
              height: 520,
              child: Column(
                children: [
                  FilterBar(
                    padding: const EdgeInsets.only(bottom: 4),
                    search: q,
                    searchHint: 'Nom, référence, code-barres…',
                    onSearch: (v) => set(() => q = v.toLowerCase()),
                    filters: [
                      if (cats.isNotEmpty) FilterSpec('category', 'Catégorie', filterOptions(all.where((p) => cats.containsKey('${p['category_id']}')), 'category_id', label: (p) => cats['${p['category_id']}']!), icon: Icons.category_outlined),
                      const FilterSpec('barcode', 'Code-barres', {'with': 'Avec code-barres', 'without': 'Sans code-barres'}, icon: Icons.qr_code_2),
                      const FilterSpec('kind', 'Vente', {'unit': 'À l\'unité', 'weighed': 'Au poids'}, icon: Icons.scale_outlined),
                      const FilterSpec('label', 'Étiquetage', {'never': 'Jamais étiqueté', 'printed': 'Déjà étiqueté', 'changed': 'Prix modifié depuis'}, icon: Icons.label_outline),
                    ],
                    values: f,
                    onFilter: (k, v) => set(() => f = {...f, k: v}),
                    count: rows.length,
                    countLabel: 'article',
                    onReset: () => set(() => (q = '', f = {})),
                  ),
                  Row(
                    children: [
                      TextButton(onPressed: () => set(() => picked.addAll(rows.map((p) => '${p['id']}'))), child: Text(tr('Tout cocher (${rows.length})'))),
                      TextButton(onPressed: () => set(picked.clear), child: Text(tr('Aucun'))),
                    ],
                  ),
                  Expanded(
                    child: ListView(
                      children: [
                        for (final p in rows)
                          CheckboxListTile(
                            dense: true,
                            value: picked.contains(p['id']),
                            onChanged: (v) => set(() => v == true ? picked.add('${p['id']}') : picked.remove(p['id'])),
                            title: Text(tr('${p['name']}')),
                            subtitle: Text(tr('${p['barcode'] ?? 'sans code-barres'}${weighable(p) ? ' · pesé (code ${p['plu']})' : ''}')),
                          ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
            actions: [
              TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))),
              FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Ajouter (${picked.length})'))),
            ],
          );
        },
      ),
    );
    if (ok == true) _addProducts(all.where((p) => picked.contains(p['id'])));
  }

  /// Produits dont le prix a changé depuis la dernière impression.
  List<Map<String, dynamic>> get _changed {
    final printed = {for (final r in LocalDb.all('lb_prints')) '${r['product_id']}': toDouble(r['price'])};
    return LocalDb.all('products').where((p) => p['active'] != false && printed.containsKey(p['id']) && (printed[p['id']]! - ttcOf(p)).abs() >= 1).toList();
  }

  Future<void> _fromSource() async {
    final s = context.read<Session>();
    final changed = _changed;
    final receipts = s.hasModule('achats') && LocalDb.hasEntity('purchase_receipts')
        ? (LocalDb.all('purchase_receipts').where((r) => r['status'] != 'cancelled').toList()..sort((a, b) => '${b['date']}'.compareTo('${a['date']}'))).take(8).toList()
        : <Map<String, dynamic>>[];
    final orders = s.hasModule('production') && LocalDb.hasEntity('mrp_orders')
        ? (LocalDb.all('mrp_orders').where((o) => o['status'] == 'done').toList()..sort((a, b) => '${b['done_at']}'.compareTo('${a['done_at']}'))).take(8).toList()
        : <Map<String, dynamic>>[];
    final pick = await showDialog<String>(
      context: context,
      builder: (c) => SimpleDialog(
        title: Text(tr('Préparer depuis…')),
        children: [
          SimpleDialogOption(
            onPressed: () => Navigator.pop(c, 'changed'),
            child: ListTile(leading: const Icon(Icons.price_change_outlined), title: Text(tr('Prix modifiés depuis la dernière impression')), subtitle: Text(tr('${changed.length} article(s)'))),
          ),
          SimpleDialogOption(
            onPressed: () => Navigator.pop(c, 'never'),
            child: ListTile(leading: Icon(Icons.new_label_outlined), title: Text(tr('Articles jamais étiquetés'))),
          ),
          for (final r in receipts)
            SimpleDialogOption(
              onPressed: () => Navigator.pop(c, 'rec:${r['id']}'),
              child: ListTile(
                leading: const Icon(Icons.move_to_inbox_outlined),
                title: Text(tr('Réception ${r['number'] ?? ''}')),
                subtitle: Text(tr('${dateFr(r['date'])} · ${(r['lines'] as List?)?.length ?? 0} ligne(s)')),
              ),
            ),
          for (final o in orders)
            SimpleDialogOption(
              onPressed: () => Navigator.pop(c, 'of:${o['id']}'),
              child: ListTile(
                leading: const Icon(Icons.precision_manufacturing_outlined),
                title: Text(tr('Fabrication ${o['number'] ?? ''}')),
                subtitle: Text(tr('${LocalDb.get('products', o['product_id'] as String?)?['name'] ?? ''} × ${qty(toDouble(o['produced_qty']))}')),
              ),
            ),
        ],
      ),
    );
    if (pick == null) return;
    if (pick == 'changed') _addProducts(changed);
    if (pick == 'never') {
      final printed = {for (final r in LocalDb.all('lb_prints')) '${r['product_id']}'};
      _addProducts(LocalDb.all('products').where((p) => p['active'] != false && p['type'] != 'service' && !printed.contains(p['id'])));
    }
    if (pick.startsWith('rec:')) {
      final r = LocalDb.get('purchase_receipts', pick.substring(4))!;
      for (final l in (r['lines'] as List? ?? [])) {
        final p = LocalDb.get('products', l['product_id'] as String?);
        if (p != null) _addProducts([p], count: toDouble(l['quantity']).ceil().clamp(1, 999));
      }
    }
    if (pick.startsWith('of:')) {
      final o = LocalDb.get('mrp_orders', pick.substring(3))!;
      final p = LocalDb.get('products', o['product_id'] as String?);
      if (p != null) _addProducts([p], count: toDouble(o['produced_qty']).ceil().clamp(1, 999));
    }
  }

  Future<void> _generateCodes() async {
    final s = context.read<Session>();
    final missing = _items.where((i) => (i.product['barcode'] ?? '').toString().isEmpty).map((i) => i.product['id']).toSet().toList();
    setState(() => _busy = true);
    try {
      final r = await s.api.post('etiquettes/barcodes', missing.isEmpty ? {} : {'product_ids': missing});
      if (!mounted) return;
      await context.read<SyncEngine>().sync();
      final d = asMap(r['data']);
      setState(() {
        for (final i in _items) {
          final fresh = LocalDb.get('products', '${i.product['id']}');
          if (fresh != null) i.product.addAll(fresh);
        }
      });
      if (mounted) toast(context, tr('${d['barcodes']} code(s)-barres et ${d['plu']} code(s) balance attribués.'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _print({bool share = false}) async {
    final s = context.read<Session>();
    final f = _format;
    final labels = _labels(s);
    if (labels.isEmpty) return;
    setState(() => _busy = true);
    try {
      final bytes = await labelsPdf(format: f, labels: labels, skip: _skip);
      final fmt = PdfPageFormat(f.pageW * PdfPageFormat.mm, f.pageH * PdfPageFormat.mm);
      if (share) {
        await Printing.sharePdf(bytes: Uint8List.fromList(bytes), filename: 'etiquettes.pdf');
      } else {
        await Printing.layoutPdf(name: 'Étiquettes', format: fmt, onLayout: (_) async => Uint8List.fromList(bytes));
      }
      // Mémorise le prix imprimé (pour repérer les étiquettes à refaire après un changement de prix)
      if (mounted) {
        final sync = context.read<SyncEngine>();
        final counts = <String, int>{};
        for (final i in _items) {
          counts['${i.product['id']}'] = (counts['${i.product['id']}'] ?? 0) + i.count;
        }
        for (final e in counts.entries) {
          final p = _items.firstWhere((i) => i.product['id'] == e.key).product;
          await sync.save('lb_prints', {'id': e.key, 'product_id': e.key, 'price': ttcOf(p), 'count': e.value, 'printed_at': DateTime.now().toUtc().toIso8601String()});
        }
        if (f.kind == 'sheet') setState(() => _skip = (_skip + labels.length) % f.perPage);
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _newTemplate() async {
    final c = {
      for (final k in ['name', 'page_w', 'page_h', 'cols', 'rows', 'label_w', 'label_h', 'margin_top', 'margin_left', 'gap_x', 'gap_y']) k: TextEditingController(),
    };
    var kind = 'sheet';
    c['page_w']!.text = '210';
    c['page_h']!.text = '297';
    c['margin_top']!.text = '0';
    c['margin_left']!.text = '0';
    c['gap_x']!.text = '0';
    c['gap_y']!.text = '0';
    final ok = await showDialog<bool>(
      context: context,
      builder: (d) => StatefulBuilder(
        builder: (d, set) {
          Widget f(String k, String label) => Expanded(
            child: Padding(
              padding: const EdgeInsets.all(4),
              child: TextField(
                controller: c[k],
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                decoration: InputDecoration(labelText: trn(label), isDense: true),
              ),
            ),
          );
          return YAlertDialog(
            title: Text(tr('Nouveau format d\'étiquettes')),
            content: SizedBox(
              width: 460,
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  TextField(
                    controller: c['name'],
                    decoration: InputDecoration(labelText: tr('Nom (ex. Planche fournisseur 4 × 12)')),
                  ),
                  const SizedBox(height: 8),
                  SegmentedButton<String>(
                    segments: [
                      ButtonSegment(value: 'sheet', label: Text(tr('Planche'))),
                      ButtonSegment(value: 'roll', label: Text(tr('Rouleau'))),
                    ],
                    selected: {kind},
                    onSelectionChanged: (v) => set(() {
                      kind = v.first;
                      if (kind == 'roll') {
                        c['cols']!.text = '1';
                        c['rows']!.text = '1';
                      }
                    }),
                  ),
                  const SizedBox(height: 8),
                  Align(
                    alignment: Alignment.centerLeft,
                    child: Text(tr('Dimensions en millimètres'), style: TextStyle(fontWeight: FontWeight.w600)),
                  ),
                  if (kind == 'sheet') Row(children: [f('page_w', 'Largeur page'), f('page_h', 'Hauteur page'), f('cols', 'Colonnes'), f('rows', 'Lignes')]),
                  Row(children: [f('label_w', 'Largeur étiquette'), f('label_h', 'Hauteur étiquette')]),
                  if (kind == 'sheet') Row(children: [f('margin_top', 'Marge haute'), f('margin_left', 'Marge gauche'), f('gap_x', 'Espace horiz.'), f('gap_y', 'Espace vert.')]),
                ],
              ),
            ),
            actions: [
              TextButton(onPressed: () => Navigator.pop(d, false), child: Text(tr('Annuler'))),
              FilledButton(onPressed: () => Navigator.pop(d, true), child: Text(tr('Enregistrer'))),
            ],
          );
        },
      ),
    );
    if (ok != true || c['name']!.text.trim().isEmpty || !mounted) return;
    double n(String k) => toDouble(c[k]!.text);
    final id = newId();
    try {
      await context.read<SyncEngine>().save('lb_templates', {
        'id': id,
        'name': c['name']!.text.trim(),
        'kind': kind,
        'page_w': kind == 'roll' ? n('label_w') : n('page_w'),
        'page_h': kind == 'roll' ? n('label_h') : n('page_h'),
        'cols': kind == 'roll' ? 1 : n('cols').round(),
        'rows': kind == 'roll' ? 1 : n('rows').round(),
        'label_w': n('label_w'),
        'label_h': n('label_h'),
        'margin_top': kind == 'roll' ? 0 : n('margin_top'),
        'margin_left': kind == 'roll' ? 0 : n('margin_left'),
        'gap_x': kind == 'roll' ? 0 : n('gap_x'),
        'gap_y': kind == 'roll' ? 0 : n('gap_y'),
        'style': 'product',
        'fields': ['name', 'price', 'barcode'],
      });
      setState(() => _formatId = id);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) => ValueListenableBuilder(valueListenable: LocalDb.listen('lb_prints'), builder: (context, _, _) => _build(context));

  Widget _build(BuildContext context) {
    final s = context.watch<Session>();
    final f = _format;
    final wide = MediaQuery.sizeOf(context).width >= 1000;
    final noCode = _items.where((i) => (i.product['barcode'] ?? '').toString().isEmpty).length;
    final list = Card(
      margin: EdgeInsets.zero,
      child: Column(
        children: [
          Align(
            alignment: Alignment.centerLeft,
            child: Padding(
              padding: const EdgeInsets.fromLTRB(12, 10, 12, 4),
              child: Wrap(
                spacing: 8,
                runSpacing: 8,
                crossAxisAlignment: WrapCrossAlignment.center,
                children: [
                  FilledButton.tonalIcon(onPressed: _pickProducts, icon: const Icon(Icons.add), label: Text(tr('Articles'))),
                  OutlinedButton.icon(onPressed: _fromSource, icon: const Icon(Icons.playlist_add), label: Text(tr('Préparer depuis…${_changed.isEmpty ? '' : ' (${_changed.length} prix modifiés)'}'))),
                  if (_items.isNotEmpty) TextButton(onPressed: () => setState(_items.clear), child: Text(tr('Vider'))),
                ],
              ),
            ),
          ),
          if (noCode > 0)
            Container(
              margin: const EdgeInsets.fromLTRB(12, 6, 12, 0),
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(color: YColors.warning.withValues(alpha: 0.12), borderRadius: BorderRadius.circular(10)),
              child: Row(
                children: [
                  const Icon(Icons.qr_code_2, color: YColors.warning),
                  const SizedBox(width: 8),
                  Expanded(child: Text(tr('$noCode article(s) sans code-barres : leur étiquette n\'aura pas de code.'))),
                  if (s.can('etiquettes.manage')) TextButton(onPressed: _busy ? null : _generateCodes, child: Text(tr('Attribuer des codes'))),
                ],
              ),
            ),
          Expanded(
            child: _items.isEmpty
                ? EmptyState(
                    icon: Icons.label_outline,
                    title: tr('Aucune étiquette à imprimer'),
                    message: tr('Ajoutez des articles, ou préparez la liste depuis une réception, une fabrication ou les prix modifiés.'),
                  )
                : ListView.separated(
                    padding: const EdgeInsets.symmetric(vertical: 6),
                    itemCount: _items.length,
                    separatorBuilder: (_, _) => const Divider(height: 1),
                    itemBuilder: (_, i) {
                      final it = _items[i];
                      final p = it.product;
                      final w = weighable(p);
                      return Padding(
                        padding: const EdgeInsets.fromLTRB(12, 6, 4, 6),
                        child: Row(
                          children: [
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(tr('${p['name']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                                  Text(
                                    tr('${money(ttcOf(p), s.currency)}${w ? ' / kg' : ''} · ${(p['barcode'] ?? '').toString().isEmpty ? 'sans code-barres' : p['barcode']}'),
                                    style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor),
                                  ),
                                ],
                              ),
                            ),
                            if (w)
                              SizedBox(
                                width: 110,
                                child: TextField(
                                  decoration: InputDecoration(isDense: true, labelText: tr('Poids'), suffixText: tr('kg')),
                                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                                  onChanged: (v) => setState(() => it.weight = toDouble(v) > 0 ? toDouble(v) : null),
                                ),
                              ),
                            IconButton(onPressed: it.count > 1 ? () => setState(() => it.count--) : null, icon: const Icon(Icons.remove_circle_outline)),
                            SizedBox(
                              width: 34,
                              child: Text(
                                tr('${it.count}'),
                                textAlign: TextAlign.center,
                                style: const TextStyle(fontWeight: FontWeight.w800),
                              ),
                            ),
                            IconButton(onPressed: () => setState(() => it.count++), icon: const Icon(Icons.add_circle_outline)),
                            IconButton(onPressed: () => setState(() => _items.removeAt(i)), icon: const Icon(Icons.close)),
                          ],
                        ),
                      );
                    },
                  ),
          ),
        ],
      ),
    );
    final options = Card(
      margin: EdgeInsets.zero,
      child: ListView(
        padding: const EdgeInsets.all(14),
        children: [
          Row(
            children: [
              Expanded(
                child: DropdownButtonFormField<String>(
                  initialValue: _formats.any((x) => x.id == _formatId) ? _formatId : labelPresets.first.id,
                  isExpanded: true,
                  decoration: InputDecoration(labelText: tr('Format')),
                  items: [
                    for (final x in _formats)
                      DropdownMenuItem(
                        value: x.id,
                        child: Text(tr(x.name), overflow: TextOverflow.ellipsis),
                      ),
                  ],
                  onChanged: (v) {
                    LocalDb.meta.put('lb_format', v);
                    setState(() {
                      _formatId = v!;
                      _fields = null;
                      _style = null;
                      _skip = 0;
                    });
                  },
                ),
              ),
              if (s.can('etiquettes.manage')) IconButton(tooltip: tr('Nouveau format'), onPressed: _newTemplate, icon: const Icon(Icons.add_box_outlined)),
            ],
          ),
          const SizedBox(height: 10),
          SegmentedButton<String>(
            segments: [
              ButtonSegment(value: 'product', label: Text(tr('Produit'))),
              ButtonSegment(value: 'shelf', label: Text(tr('Rayon'))),
              ButtonSegment(value: 'barcode', label: Text(tr('Code seul'))),
            ],
            selected: {f.style},
            onSelectionChanged: (v) => setState(() => _style = v.first),
          ),
          const SizedBox(height: 10),
          Wrap(
            spacing: 6,
            runSpacing: 6,
            children: [
              for (final e in labelFields.entries)
                FilterChip(
                  label: Text(tr(e.value)),
                  selected: f.fields.contains(e.key),
                  onSelected: (v) => setState(() {
                    final set = {...f.fields};
                    v ? set.add(e.key) : set.remove(e.key);
                    _fields = set;
                  }),
                ),
            ],
          ),
          if (f.fields.contains('date'))
            Padding(
              padding: const EdgeInsets.only(top: 10),
              child: TextFormField(
                initialValue: _date,
                decoration: InputDecoration(labelText: tr('Texte de la date'), helperText: tr('Ex. « Prix au 23/10/2026 » ou « À consommer avant le … »')),
                onChanged: (v) => setState(() => _date = v),
              ),
            ),
          if (f.kind == 'sheet') ...[
            const SizedBox(height: 10),
            Row(
              children: [
                Expanded(child: Text(tr('Commencer à l\'étiquette n° ${_skip + 1} (planche déjà entamée)'))),
                IconButton(onPressed: _skip > 0 ? () => setState(() => _skip--) : null, icon: const Icon(Icons.remove)),
                IconButton(onPressed: _skip < f.perPage - 1 ? () => setState(() => _skip++) : null, icon: const Icon(Icons.add)),
              ],
            ),
          ],
          const SizedBox(height: 10),
          Text(
            tr(f.kind == 'sheet' ? '$_total étiquette(s) · ${((_total + _skip) / f.perPage).ceil()} planche(s) de ${f.perPage}' : '$_total étiquette(s) ${qty(f.labelW)} × ${qty(f.labelH)} mm'),
            style: const TextStyle(fontWeight: FontWeight.w700),
          ),
          const SizedBox(height: 10),
          LabelSheetPreview(format: f, labels: _labels(s), skip: f.kind == 'sheet' ? _skip : 0),
          const SizedBox(height: 12),
          Row(
            children: [
              Expanded(
                child: FilledButton.icon(
                  style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(48)),
                  onPressed: _items.isEmpty || _busy ? null : _print,
                  icon: const Icon(Icons.print_outlined),
                  label: Text(tr('Imprimer')),
                ),
              ),
              const SizedBox(width: 8),
              OutlinedButton.icon(onPressed: _items.isEmpty || _busy ? null : () => _print(share: true), icon: const Icon(Icons.picture_as_pdf_outlined), label: Text(tr('PDF'))),
            ],
          ),
          const SizedBox(height: 6),
          Text(
            tr(f.kind == 'roll'
                ? 'Imprimante d\'étiquettes (Zebra, Xprinter, Godex…) : choisissez-la dans la fenêtre d\'impression, format ${qty(f.labelW)} × ${qty(f.labelH)} mm, échelle 100 %.'
                : 'Imprimez à l\'échelle 100 % (« taille réelle »), sans ajuster à la page.'),
            style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor),
          ),
        ],
      ),
    );
    return Padding(
      padding: const EdgeInsets.all(12),
      child: wide
          ? Row(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Expanded(child: list),
                const SizedBox(width: 12),
                SizedBox(width: 440, child: options),
              ],
            )
          : DefaultTabController(
              length: 2,
              child: Column(
                children: [
                  TabBar(
                    tabs: [
                      Tab(text: tr('Articles ($_total)')),
                      Tab(text: tr('Format et impression')),
                    ],
                  ),
                  const SizedBox(height: 8),
                  Expanded(child: TabBarView(children: [list, options])),
                ],
              ),
            ),
    );
  }
}

/// Aperçu de la première planche (ou de la première étiquette d'un rouleau), dessiné à l'écran.
class LabelSheetPreview extends StatelessWidget {
  const LabelSheetPreview({super.key, required this.format, required this.labels, this.skip = 0});

  final LabelFormat format;
  final List<LabelData> labels;
  final int skip;

  @override
  Widget build(BuildContext context) {
    final f = format;
    return LayoutBuilder(
      builder: (context, box) {
        final roll = f.kind == 'roll';
        final pageW = roll ? f.labelW : f.pageW;
        final pageH = roll ? f.labelH : f.pageH;
        final scale = (box.maxWidth / pageW).clamp(0.5, roll ? 6.0 : 3.0).toDouble(); // pixels par mm
        final cells = roll ? labels.take(1).toList() : [...List<LabelData?>.filled(skip, null), ...labels].take(f.perPage).toList();
        return Center(
          child: Container(
            width: pageW * scale,
            height: pageH * scale,
            decoration: BoxDecoration(
              color: Colors.white,
              border: Border.all(color: YColors.line),
              boxShadow: const [BoxShadow(color: Color(0x14000000), blurRadius: 6)],
            ),
            child: Stack(
              children: [
                for (var i = 0; i < (roll ? 1 : f.perPage); i++)
                  Positioned(
                    left: (roll ? 0 : f.marginLeft + (i % f.cols) * (f.labelW + f.gapX)) * scale,
                    top: (roll ? 0 : f.marginTop + (i ~/ f.cols) * (f.labelH + f.gapY)) * scale,
                    width: f.labelW * scale,
                    height: f.labelH * scale,
                    child: Container(
                      decoration: BoxDecoration(
                        border: Border.all(color: const Color(0xFFDDE3EE), width: 0.5),
                        borderRadius: BorderRadius.circular(1.5 * scale),
                      ),
                      child: i < cells.length && cells[i] != null ? _FlutterLabel(format: f, data: cells[i]!, scale: scale) : null,
                    ),
                  ),
              ],
            ),
          ),
        );
      },
    );
  }
}

class _FlutterLabel extends StatelessWidget {
  const _FlutterLabel({required this.format, required this.data, required this.scale});

  final LabelFormat format;
  final LabelData data;
  final double scale;

  @override
  Widget build(BuildContext context) {
    final f = format;
    final m = labelMetrics(f);
    bool has(String k) => f.fields.contains(k);
    final shelf = f.style == 'shelf';
    // Taille de police : hauteur en mm × pixels/mm (approximation de la hauteur des capitales)
    TextStyle st(double mm, {bool bold = false, Color? color}) =>
        TextStyle(fontSize: mm * scale * 1.0, height: 1.1, fontWeight: bold ? FontWeight.w700 : FontWeight.w400, color: color ?? Colors.black);
    return ClipRect(
      child: Padding(
        padding: EdgeInsets.all(m.pad * scale),
        child: Column(
          crossAxisAlignment: shelf ? CrossAxisAlignment.start : CrossAxisAlignment.center,
          children: [
            if (has('company') && data.company != null) Text(tr(data.company!), style: st(m.small), maxLines: 1, overflow: TextOverflow.clip),
            if (has('name')) Text(tr(data.name), style: st(m.name, bold: true), maxLines: 2, overflow: TextOverflow.clip, textAlign: shelf ? TextAlign.left : TextAlign.center),
            if (has('category') && data.category != null) Text(tr(data.category!), style: st(m.small, color: Colors.black54), maxLines: 1),
            if (data.weight != null) Text(tr(data.weight!), style: st(m.small)),
            if (has('price') && data.price != null)
              FittedBox(
                fit: BoxFit.scaleDown,
                child: Text(tr(data.price!), style: st(m.price, bold: true), maxLines: 1),
              ),
            if (has('unit_price') && data.unitPrice != null) Text(tr(data.unitPrice!), style: st(m.small), maxLines: 1),
            const Spacer(),
            if (has('barcode') && (data.barcode ?? '').isNotEmpty)
              SizedBox(
                width: (f.labelW - 2 * m.pad) * scale * (f.style == 'barcode' ? 1 : (shelf ? 0.55 : 0.85)),
                height: m.barcode * scale,
                child: CustomPaint(painter: _BarcodePainter(data.barcode!, m.small * scale)),
              ),
            if ((has('sku') && data.sku != null) || (has('date') && data.date != null))
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Text(tr(has('sku') ? (data.sku ?? '') : ''), style: st(m.small * 0.9)),
                  Text(tr(has('date') ? (data.date ?? '') : ''), style: st(m.small * 0.9)),
                ],
              ),
          ],
        ),
      ),
    );
  }
}

class _BarcodePainter extends CustomPainter {
  _BarcodePainter(this.data, this.font);

  final String data;
  final double font;

  @override
  void paint(Canvas canvas, Size size) {
    final black = Paint()..color = Colors.black;
    try {
      for (final e in barcodeFor(data).make(data, width: size.width, height: size.height, drawText: true, fontHeight: font)) {
        if (e is BarcodeBar && e.black) {
          canvas.drawRect(Rect.fromLTWH(e.left, e.top, e.width, e.height), black);
        } else if (e is BarcodeText) {
          final tp = TextPainter(
            text: TextSpan(
              text: e.text,
              style: TextStyle(fontSize: font, color: Colors.black),
            ),
            textDirection: TextDirection.ltr,
          )..layout();
          final dx = switch (e.align) {
            BarcodeTextAlign.left => e.left,
            BarcodeTextAlign.right => e.left + e.width - tp.width,
            _ => e.left + (e.width - tp.width) / 2,
          };
          tp.paint(canvas, Offset(dx, e.top));
        }
      }
    } catch (_) {
      // Code illisible pour ce format : on n'affiche rien plutôt que d'interrompre l'aperçu.
    }
  }

  @override
  bool shouldRepaint(covariant _BarcodePainter old) => old.data != data || old.font != font;
}
