import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../../core/local_db.dart';
import '../../core/stock_calc.dart';
import '../../core/sync_engine.dart';
import '../../state/session.dart';
import '../theme.dart';
import 'common.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

String _n(dynamic v) {
  if (v == null || '$v'.isEmpty) return '';
  final d = toDouble(v);
  return d == d.roundToDouble() ? '${d.toInt()}' : '$d';
}

final _numFmt = [FilteringTextInputFormatter.allow(RegExp(r'[0-9.,]'))];

/// Conditionnements d'un article (module tarifs) : carton de 24, pack de 6… avec code-barres et prix propres.
class PackagingsEditor extends StatefulWidget {
  const PackagingsEditor({super.key, required this.items, required this.unit});

  /// Liste modifiée en place.
  final List<Map<String, dynamic>> items;
  final String unit;

  @override
  State<PackagingsEditor> createState() => _PackagingsEditorState();
}

class _PackagingsEditorState extends State<PackagingsEditor> {
  final _ctrls = <List<TextEditingController>>[];
  static const _keys = ['name', 'factor', 'barcode', 'sale_price', 'purchase_price'];

  @override
  void initState() {
    super.initState();
    for (final p in widget.items) {
      _ctrls.add([for (final k in _keys) TextEditingController(text: k == 'name' || k == 'barcode' ? '${p[k] ?? ''}' : _n(p[k]))]);
    }
  }

  void _write(int i) {
    final c = _ctrls[i];
    String? t(int j) => c[j].text.trim().isEmpty ? null : c[j].text.trim();
    widget.items[i] = {
      'name': t(0) ?? '',
      'factor': toDouble(c[1].text),
      'barcode': ?t(2),
      if (t(3) != null) 'sale_price': toDouble(c[3].text),
      if (t(4) != null) 'purchase_price': toDouble(c[4].text),
    };
  }

  @override
  Widget build(BuildContext context) {
    final wide = MediaQuery.of(context).size.width >= 700;
    Widget f(int i, int j, String label, {bool num = false, double? width}) {
      final field = TextField(
        controller: _ctrls[i][j],
        keyboardType: num ? const TextInputType.numberWithOptions(decimal: true) : null,
        inputFormatters: num ? _numFmt : null,
        decoration: InputDecoration(isDense: true, labelText: trn(label)),
        onChanged: (_) => _write(i),
      );
      return wide && width != null ? SizedBox(width: width, child: field) : Expanded(child: field);
    }

    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Text(tr('Conditionnements'), style: Theme.of(context).textTheme.titleSmall),
      Text(tr('Vendez ou achetez par carton, pack… Le stock reste compté en ${widget.unit}. Sans prix, celui de l\'unité × contenance s\'applique.'),
          style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
      const SizedBox(height: 8),
      for (var i = 0; i < _ctrls.length; i++)
        Padding(
          padding: const EdgeInsets.only(bottom: 8),
          child: wide
              ? Row(children: [
                  f(i, 0, 'Nom (ex. Carton)'),
                  const SizedBox(width: 6),
                  f(i, 1, 'Contient', num: true, width: 80),
                  const SizedBox(width: 6),
                  f(i, 2, 'Code-barres', width: 130),
                  const SizedBox(width: 6),
                  f(i, 3, 'Prix vente HT', num: true, width: 100),
                  const SizedBox(width: 6),
                  f(i, 4, 'Prix achat HT', num: true, width: 100),
                  IconButton(onPressed: () => setState(() => [_ctrls.removeAt(i), widget.items.removeAt(i)]), icon: const Icon(Icons.close, size: 18)),
                ])
              : Column(children: [
                  Row(children: [f(i, 0, 'Nom'), const SizedBox(width: 6), f(i, 1, 'Contient', num: true), IconButton(onPressed: () => setState(() => [_ctrls.removeAt(i), widget.items.removeAt(i)]), icon: const Icon(Icons.close, size: 18))]),
                  const SizedBox(height: 6),
                  Row(children: [f(i, 2, 'Code-barres'), const SizedBox(width: 6), f(i, 3, 'Vente HT', num: true), const SizedBox(width: 6), f(i, 4, 'Achat HT', num: true)]),
                ]),
        ),
      Align(
        alignment: Alignment.centerLeft,
        child: TextButton.icon(
          onPressed: _ctrls.length >= 10
              ? null
              : () => setState(() {
                    _ctrls.add([for (final _ in _keys) TextEditingController()]);
                    widget.items.add({'name': '', 'factor': 0});
                  }),
          icon: const Icon(Icons.add),
          label: Text(tr('Ajouter un conditionnement')),
        ),
      ),
    ]);
  }
}

/// Attributs d'un modèle (module variantes) : Taille : S, M, L — Couleur : Rouge, Bleu — et suppléments de prix.
class VariantAttributesEditor extends StatefulWidget {
  const VariantAttributesEditor({super.key, required this.attributes, required this.extras});

  final List<Map<String, dynamic>> attributes;
  final Map<String, Map<String, dynamic>> extras;

  @override
  State<VariantAttributesEditor> createState() => _VariantAttributesEditorState();
}

class _VariantAttributesEditorState extends State<VariantAttributesEditor> {
  late final List<TextEditingController> _names = [for (final a in widget.attributes) TextEditingController(text: '${a['name'] ?? ''}')];
  late final List<TextEditingController> _values = [for (final a in widget.attributes) TextEditingController(text: ((a['values'] as List?) ?? const []).join(', '))];

  List<String> _vals(int i) => _values[i].text.split(RegExp(r'[,;\n]')).map((e) => e.trim()).where((e) => e.isNotEmpty).toSet().toList();

  void _write(int i) {
    widget.attributes[i] = {'name': _names[i].text.trim(), 'values': _vals(i)};
  }

  int get _combos => widget.attributes.isEmpty ? 0 : widget.attributes.fold(1, (s, a) => s * ((a['values'] as List?)?.length ?? 0));

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Text(tr('Jusqu\'à 3 attributs ; chaque combinaison devient un article avec son stock et son code-barres.'), style: TextStyle(fontSize: 12, color: hint)),
      const SizedBox(height: 8),
      for (var i = 0; i < _names.length; i++)
        Padding(
          padding: const EdgeInsets.only(bottom: 8),
          child: Row(children: [
            SizedBox(width: 130, child: TextField(controller: _names[i], decoration: InputDecoration(isDense: true, labelText: tr('Attribut')), onChanged: (_) => setState(() => _write(i)))),
            const SizedBox(width: 8),
            Expanded(child: TextField(controller: _values[i], decoration: InputDecoration(isDense: true, labelText: tr('Valeurs (séparées par des virgules)')), onChanged: (_) => setState(() => _write(i)))),
            IconButton(
              onPressed: () => setState(() {
                widget.extras.remove(_names[i].text.trim());
                _names.removeAt(i);
                _values.removeAt(i);
                widget.attributes.removeAt(i);
              }),
              icon: const Icon(Icons.close, size: 18),
            ),
          ]),
        ),
      Row(children: [
        TextButton.icon(
          onPressed: _names.length >= 3
              ? null
              : () => setState(() {
                    _names.add(TextEditingController());
                    _values.add(TextEditingController());
                    widget.attributes.add({'name': '', 'values': <String>[]});
                  }),
          icon: const Icon(Icons.add),
          label: Text(tr('Ajouter un attribut')),
        ),
        const Spacer(),
        if (_combos > 0) Text(tr('$_combos combinaison${_combos > 1 ? 's' : ''}'), style: TextStyle(color: _combos > 300 ? YColors.danger : hint, fontWeight: FontWeight.w600)),
      ]),
      if (widget.attributes.any((a) => (a['values'] as List).isNotEmpty)) ...[
        const SizedBox(height: 4),
        Text(tr('Suppléments de prix HT (facultatif)'), style: Theme.of(context).textTheme.titleSmall),
        const SizedBox(height: 4),
        Wrap(spacing: 8, runSpacing: 8, children: [
          for (final a in widget.attributes)
            for (final v in (a['values'] as List))
              SizedBox(
                width: 150,
                child: TextFormField(
                  key: ValueKey('x-${a['name']}-$v'),
                  initialValue: _n(widget.extras['${a['name']}']?['$v']),
                  keyboardType: const TextInputType.numberWithOptions(decimal: true, signed: true),
                  decoration: InputDecoration(isDense: true, labelText: trn('${a['name']} : $v'), prefixText: '+ '),
                  onChanged: (t) {
                    final m = widget.extras.putIfAbsent('${a['name']}', () => {});
                    toDouble(t) == 0 ? m.remove('$v') : m['$v'] = toDouble(t);
                  },
                ),
              ),
        ]),
      ],
    ]);
  }
}

/// Variantes générées d'un modèle : liste avec stock, génération des manquantes, report des champs communs.
class VariantsPanel extends StatefulWidget {
  const VariantsPanel({super.key, required this.template});

  final Map<String, dynamic> template;

  @override
  State<VariantsPanel> createState() => _VariantsPanelState();
}

class _VariantsPanelState extends State<VariantsPanel> {
  bool _busy = false;

  static const _fields = {
    'sale_price': 'Prix de vente (modèle + suppléments)',
    'purchase_price': 'Prix d\'achat',
    'tax_rate': 'TVA',
    'unit': 'Unité',
    'category_id': 'Catégorie',
    'min_stock': 'Seuil d\'alerte',
    'description': 'Description',
    'active': 'Actif',
  };

  Future<void> _generate() async {
    final sync = context.read<SyncEngine>();
    final api = context.read<Session>().api;
    setState(() => _busy = true);
    try {
      await sync.sync();
      final r = await api.post('variantes/templates/${widget.template['id']}/generate');
      final created = [for (final p in (r['data'] as List? ?? const [])) asMap(p)];
      await LocalDb.putMany('products', created);
      if (mounted) toast(context, created.isEmpty ? 'Toutes les combinaisons existent déjà.' : '${created.length} variante(s) créée(s).');
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _propagate() async {
    final picked = <String>{'sale_price', 'tax_rate'};
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => StatefulBuilder(
        builder: (c, set) => YAlertDialog(
          title: Text(tr('Répercuter sur les variantes')),
          content: SizedBox(
            width: 380,
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              for (final e in _fields.entries)
                CheckboxListTile(dense: true, value: picked.contains(e.key), title: Text(tr(e.value)), onChanged: (v) => set(() => v == true ? picked.add(e.key) : picked.remove(e.key))),
            ]),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))),
            FilledButton(onPressed: picked.isEmpty ? null : () => Navigator.pop(c, true), child: Text(tr('Répercuter'))),
          ],
        ),
      ),
    );
    if (ok != true || !mounted) return;
    final sync = context.read<SyncEngine>();
    final api = context.read<Session>().api;
    setState(() => _busy = true);
    try {
      await sync.sync();
      final r = await api.post('variantes/templates/${widget.template['id']}/propagate', {'fields': picked.toList()});
      await sync.sync();
      if (mounted) toast(context, tr('${r['data']?['updated'] ?? 0} variante(s) mise(s) à jour.'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final currency = context.read<Session>().currency;
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('products'),
      builder: (context, _, _) {
        final variants = LocalDb.all('products').where((p) => p['parent_id'] == widget.template['id']).toList()..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));
        final stock = LocalDb.hasEntity('stock_moves') ? StockCalc.totals() : const <String, double>{};
        final local = widget.template['_local'] == true;
        return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Row(children: [
            Text(tr('Variantes (${variants.length})'), style: Theme.of(context).textTheme.titleSmall),
            const Spacer(),
            if (variants.isNotEmpty) TextButton(onPressed: _busy || local ? null : _propagate, child: Text(tr('Répercuter…'))),
            FilledButton.tonalIcon(
              onPressed: _busy || local ? null : _generate,
              icon: _busy ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2)) : const Icon(Icons.auto_awesome_motion, size: 18),
              label: Text(tr('Générer')),
            ),
          ]),
          if (local) Text(tr('Enregistrez et synchronisez le modèle avant de générer ses variantes.'), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
          for (final v in variants)
            ListTile(
              dense: true,
              contentPadding: EdgeInsets.zero,
              title: Text(tr((asMap(v['variant_values'])).values.join(' / ')), style: TextStyle(fontWeight: FontWeight.w600, color: v['active'] == false ? Colors.grey : null)),
              subtitle: Text(tr([if (v['sku'] != null) '${v['sku']}', if (v['barcode'] != null) '${v['barcode']}'].join(' · '))),
              trailing: Text(tr('${money(toDouble(v['sale_price']), currency)}   ${qty(stock[v['id']] ?? 0)} en stock')),
            ),
        ]);
      },
    );
  }
}
