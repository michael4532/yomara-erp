import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../../core/local_db.dart';
import '../../core/stock_calc.dart';
import '../../core/sync_engine.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/custom_fields_editor.dart';
import '../widgets/filter_bar.dart';
import '../widgets/product_extras.dart';
import 'labels_screen.dart' show LabelItem, labelsRoute;
import '../../i18n/i18n.dart';
import '../../core/json.dart';

class ProductsScreen extends StatefulWidget {
  const ProductsScreen({super.key});

  @override
  State<ProductsScreen> createState() => _ProductsScreenState();
}

class _ProductsScreenState extends State<ProductsScreen> {
  String _q = '';
  String _status = '';
  Map<String, String?> _f = {};

  static const _types = {'': 'Tous', 'product': 'Produits', 'service': 'Services', 'template': 'Modèles'};

  bool _typeMatch(Map<String, dynamic> p, String t) => switch (t) {
        'product' => p['type'] != 'service' && p['is_template'] != true,
        'service' => p['type'] == 'service',
        'template' => p['is_template'] == true,
        _ => true,
      };

  String _stockState(Map<String, dynamic> p, Map<String, double> stock) {
    if (p['type'] == 'service' || p['track_stock'] == false || p['is_template'] == true) return 'none';
    final q = stock[p['id']] ?? 0;
    if (q <= 0) return 'out';
    return q <= ((p['min_stock'] as num?) ?? 0) ? 'low' : 'ok';
  }

  List<Widget> _badges(Map<String, dynamic> p) => [
        if (p['is_template'] == true) Padding(padding: EdgeInsets.only(left: 6), child: StatusChip(tr('Modèle'), color: YColors.blue)),
        if (p['tracking'] == 'lot') Padding(padding: EdgeInsets.only(left: 6), child: Tooltip(message: tr('Suivi par lot'), child: Icon(Icons.inventory_2_outlined, size: 15))),
        if (p['tracking'] == 'serial') Padding(padding: EdgeInsets.only(left: 6), child: Tooltip(message: tr('Suivi par n° de série'), child: Icon(Icons.qr_code_2, size: 15))),
        if ((p['packagings'] as List?)?.isNotEmpty == true) Padding(padding: EdgeInsets.only(left: 6), child: Tooltip(message: tr('Conditionnements'), child: Icon(Icons.all_inbox_outlined, size: 15))),
      ];

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final canEdit = session.can('core.products.manage');
    final withStock = session.hasModule('stock') && LocalDb.hasEntity('stock_moves');

    return Column(children: [
      PageHeader(
        title: tr('Produits & services'),
        actions: [
          if (canEdit) FilledButton.icon(onPressed: () => editProduct(context), icon: const Icon(Icons.add), label: Text(tr('Nouveau produit'))),
        ],
      ),
      Expanded(
        child: ValueListenableBuilder(
          valueListenable: LocalDb.listen('products'),
          builder: (context, _, _) {
            final stock = withStock ? StockCalc.totals() : const <String, double>{};
            final cats = {for (final c in LocalDb.all('product_categories')) '${c['id']}': '${c['name']}'};
            final all = LocalDb.all('products');
            final base = all.where((p) {
              if (_q.isNotEmpty && !'${p['name']} ${p['sku'] ?? ''} ${p['barcode'] ?? ''}'.toLowerCase().contains(_q)) return false;
              final c = _f['category'], a = _f['active'], u = _f['unit'], t = _f['tax'], trk = _f['tracking'], st = _f['stock'];
              if (c != null && '${p['category_id']}' != c) return false;
              if (a != null && (a == 'active') != (p['active'] != false)) return false;
              if (u != null && '${p['unit'] ?? ''}' != u) return false;
              if (t != null && number(p['tax_rate'] as num?) != t) return false;
              if (trk != null && '${p['tracking'] ?? 'none'}' != trk) return false;
              if (st != null && _stockState(p, stock) != st) return false;
              return true;
            }).toList();
            final items = base.where((p) => _typeMatch(p, _status)).toList()
              ..sort((a, b) => '${a['name']}'.toLowerCase().compareTo('${b['name']}'.toLowerCase()));
            final taxes = <String, String>{for (final p in all) number(p['tax_rate'] as num?): '${number(p['tax_rate'] as num?)} %'};
            final filtering = _q.isNotEmpty || _status.isNotEmpty || _f.values.any((v) => v != null);

            final bar = FilterBar(
              search: _q,
              searchHint: 'Nom, référence ou code-barres…',
              onSearch: (v) => setState(() => _q = v.toLowerCase()),
              statuses: _types,
              status: _status,
              onStatus: (v) => setState(() => _status = v),
              statusCounts: {for (final k in _types.keys) k: base.where((p) => _typeMatch(p, k)).length},
              filters: [
                if (cats.isNotEmpty) FilterSpec('category', 'Catégorie', filterOptions(all.where((p) => cats.containsKey('${p['category_id']}')), 'category_id', label: (p) => cats['${p['category_id']}']!), icon: Icons.category_outlined),
                const FilterSpec('active', 'État', {'active': 'Actifs', 'inactive': 'Archivés'}, icon: Icons.toggle_on_outlined),
                if (withStock) const FilterSpec('stock', 'Stock', {'ok': 'En stock', 'low': 'En alerte', 'out': 'En rupture', 'none': 'Non suivi'}, icon: Icons.warehouse_outlined),
                FilterSpec('unit', 'Unité', filterOptions(all, 'unit'), icon: Icons.straighten_outlined),
                FilterSpec('tax', 'TVA', taxes, icon: Icons.percent_rounded),
                const FilterSpec('tracking', 'Traçabilité', {'none': 'Aucune', 'lot': 'Par lot', 'serial': 'Par n° de série'}, icon: Icons.qr_code_2),
              ],
              values: _f,
              onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
              count: items.length,
              countLabel: 'article',
              onReset: () => setState(() => (_q = '', _status = '', _f = {})),
            );

            if (items.isEmpty) {
              return Column(children: [
                bar,
                Expanded(child: EmptyState(icon: Icons.sell_outlined, title: !filtering ? 'Aucun produit' : 'Aucun résultat', message: !filtering ? 'Créez votre catalogue de produits et de services.' : null)),
              ]);
            }

            Widget stockCell(Map<String, dynamic> p) {
              if (p['is_template'] == true) {
                final vs = LocalDb.all('products').where((v) => v['parent_id'] == p['id']);
                return Text(tr('${qty(vs.fold<double>(0, (s, v) => s + (stock[v['id']] ?? 0)))} (${vs.length} var.)'), style: TextStyle(color: Theme.of(context).hintColor));
              }
              if (p['type'] == 'service' || p['track_stock'] == false) return const Text('—');
              final q = stock[p['id']] ?? 0;
              final low = q <= ((p['min_stock'] as num?) ?? 0);
              final c = low ? YColors.danger : YColors.success;
              return Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                decoration: BoxDecoration(color: c.withValues(alpha: .10), borderRadius: BorderRadius.circular(8)),
                child: Text(tr('${qty(q)} ${p['unit'] ?? ''}'), style: TextStyle(color: Theme.of(context).brightness == Brightness.dark ? c : Color.lerp(c, Colors.black, .15), fontWeight: FontWeight.w600, fontSize: 13)),
              );
            }

            Widget badge(Map<String, dynamic> p) {
              final service = p['type'] == 'service';
              final c = p['active'] == false ? Colors.grey : (service ? const Color(0xFF7C3AED) : const Color(0xFF0D9488));
              return Container(
                width: 34,
                height: 34,
                margin: const EdgeInsets.only(right: 12),
                decoration: BoxDecoration(color: c.withValues(alpha: .12), borderRadius: BorderRadius.circular(10)),
                child: Icon(p['is_template'] == true ? Icons.style_outlined : (service ? Icons.design_services_outlined : Icons.inventory_2_outlined), size: 18, color: c),
              );
            }

            final active = all.where((p) => p['active'] != false && p['is_template'] != true).toList();
            final value = withStock ? active.fold<double>(0, (a, p) => a + (stock[p['id']] ?? 0).clamp(0, double.infinity) * toDouble(p['purchase_price'] ?? 0)) : 0.0;
            return Column(children: [
              StatStrip(tiles: [
                StatTile(label: tr('Articles actifs'), value: '${active.length}', detail: tr('${active.where((p) => p['type'] == 'service').length} service(s)'), icon: Icons.sell_outlined),
                if (withStock) ...[
                  StatTile(label: tr('En alerte'), value: '${active.where((p) => _stockState(p, stock) == 'low').length}', detail: tr('sous le seuil minimum'), icon: Icons.warning_amber_rounded, color: YColors.warning),
                  StatTile(label: tr('En rupture'), value: '${active.where((p) => _stockState(p, stock) == 'out').length}', detail: tr('stock à zéro'), icon: Icons.remove_shopping_cart_outlined, color: YColors.danger),
                  StatTile(label: tr('Valeur du stock'), value: money(value, session.currency), detail: tr('au prix d\'achat'), icon: Icons.inventory_2_outlined, color: YColors.success),
                ],
              ]),
              bar,
              Expanded(
                child: ResponsiveList<Map<String, dynamic>>(
              items: items,
              columns: ['Produit', 'Référence', 'Prix de vente', 'TVA', if (withStock) 'Stock'],
              onTap: canEdit ? (p) => editProduct(context, p) : null,
              cells: (p) => [
                Row(children: [
                  badge(p),
                  Flexible(child: Text(tr('${p['name']}'), style: TextStyle(fontWeight: FontWeight.w600, color: p['active'] == false ? Colors.grey : null), overflow: TextOverflow.ellipsis)),
                  ..._badges(p),
                ]),
                Text(tr('${p['sku'] ?? ''}')),
                Text(tr(money(p['sale_price'] as num?, session.currency))),
                Text(tr('${number(p['tax_rate'] as num?)} %')),
                if (withStock) stockCell(p),
              ],
              mobileTile: (p) => Row(children: [
                badge(p),
                Expanded(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(tr('${p['name']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                    Text(tr(money(p['sale_price'] as num?, session.currency)), style: TextStyle(color: Theme.of(context).hintColor)),
                  ]),
                ),
                if (withStock) stockCell(p),
              ]),
                ),
              ),
            ]);
          },
        ),
      ),
    ]);
  }
}

const units = ['unité', 'kg', 'g', 'litre', 'mètre', 'carton', 'sac', 'paquet', 'boîte', 'heure', 'jour', 'forfait'];

Future<Map<String, dynamic>?> editProduct(BuildContext context, [Map<String, dynamic>? product]) => showFormDialog<Map<String, dynamic>>(
      context,
      title: product == null ? 'Nouveau produit' : 'Modifier le produit',
      builder: (_) => _ProductForm(product: product),
    );

class _ProductForm extends StatefulWidget {
  const _ProductForm({this.product});

  final Map<String, dynamic>? product;

  @override
  State<_ProductForm> createState() => _ProductFormState();
}

class _ProductFormState extends State<_ProductForm> {
  final _key = GlobalKey<FormState>();
  late final Map<String, TextEditingController> _c;
  late String _type;
  late String _unit;
  late bool _trackStock;
  late bool _active;
  late final Map<String, dynamic> _custom = asMap(widget.product?['custom']);
  late String _tracking = '${widget.product?['tracking'] ?? 'none'}';
  late bool _useExpiry = widget.product?['use_expiry'] == true;
  late final List<Map<String, dynamic>> _packagings = [for (final p in (widget.product?['packagings'] as List? ?? const [])) asMap(p)];
  late bool _isTemplate = widget.product?['is_template'] == true;
  late final List<Map<String, dynamic>> _attrs = [
    for (final a in (widget.product?['variant_attributes'] as List? ?? const [])) {'name': (a as Map)['name'], 'values': List<String>.from((a['values'] as List? ?? const []).map((e) => '$e'))},
  ];
  late final Map<String, Map<String, dynamic>> _extras = {
    for (final e in ((widget.product?['price_extras'] as Map?) ?? const {}).entries) '${e.key}': asMap(e.value),
  };
  bool get _isVariant => widget.product?['parent_id'] != null;

  @override
  void initState() {
    super.initState();
    final p = widget.product ?? {};
    final taxDefault = context.read<Session>().companySettings['tax_rate'] ?? 18;
    _type = (p['type'] as String?) ?? 'product';
    _unit = units.contains(p['unit']) ? p['unit'] as String : 'unité';
    _trackStock = (p['track_stock'] as bool?) ?? true;
    _active = (p['active'] as bool?) ?? true;
    String n(dynamic v) => v == null ? '' : (v is num && v == v.roundToDouble() ? '${v.toInt()}' : '$v');
    _c = {
      'name': TextEditingController(text: '${p['name'] ?? ''}'),
      'sku': TextEditingController(text: '${p['sku'] ?? ''}'),
      'barcode': TextEditingController(text: '${p['barcode'] ?? ''}'),
      'sale_price': TextEditingController(text: n(p['sale_price'])),
      'purchase_price': TextEditingController(text: n(p['purchase_price'])),
      'tax_rate': TextEditingController(text: n(p['tax_rate'] ?? taxDefault)),
      'min_stock': TextEditingController(text: n(p['min_stock'] ?? 0)),
      'description': TextEditingController(text: '${p['description'] ?? ''}'),
      'plu': TextEditingController(text: '${p['plu'] ?? ''}'),
      'shelf_life_days': TextEditingController(text: n(p['shelf_life_days'])),
      'expiry_alert_days': TextEditingController(text: n(p['expiry_alert_days'])),
    };
  }

  Future<void> _save() async {
    if (!_key.currentState!.validate()) return;
    String? t(String k) => _c[k]!.text.trim().isEmpty ? null : _c[k]!.text.trim();
    final champs = context.read<Session>().hasModule('champs');
    final err = champs ? customRequiredError('products', _custom) : null;
    if (err != null) return toast(context, err, error: true);
    final session = context.read<Session>();
    final lots = session.hasModule('lots') && _type == 'product' && _trackStock && !_isTemplate;
    if (_packagings.any((p) => '${p['name']}'.trim().isNotEmpty && toDouble(p['factor']) <= 1)) {
      return toast(context, tr('Indiquez pour chaque conditionnement le nombre d\'unités qu\'il contient (plus de 1).'), error: true);
    }
    final saved = await context.read<SyncEngine>().save('products', {
      if (widget.product != null) 'id': widget.product!['id'],
      if (champs) 'custom': _custom,
      'type': _type,
      'name': t('name'),
      'sku': t('sku'),
      'barcode': t('barcode'),
      'description': t('description'),
      'unit': _unit,
      'sale_price': toDouble(_c['sale_price']!.text),
      'purchase_price': toDouble(_c['purchase_price']!.text),
      'tax_rate': toDouble(_c['tax_rate']!.text),
      'min_stock': toDouble(_c['min_stock']!.text),
      'track_stock': _type == 'product' && _trackStock && !_isTemplate,
      if (session.hasModule('lots')) ...{
        'tracking': lots ? _tracking : 'none',
        'use_expiry': lots && _tracking != 'none' && _useExpiry,
        'shelf_life_days': lots && _useExpiry && t('shelf_life_days') != null ? toDouble(_c['shelf_life_days']!.text).toInt() : null,
        'expiry_alert_days': lots && _useExpiry && t('expiry_alert_days') != null ? toDouble(_c['expiry_alert_days']!.text).toInt() : null,
      },
      if (session.hasModule('tarifs')) 'packagings': _packagings.where((p) => '${p['name']}'.trim().isNotEmpty).toList(),
      if (session.hasModule('variantes') && !_isVariant) ...{
        'is_template': _isTemplate,
        if (_isTemplate) 'variant_attributes': _attrs.where((a) => '${a['name']}'.trim().isNotEmpty || (a['values'] as List).isNotEmpty).toList(),
        if (_isTemplate) 'price_extras': _extras,
      },
      'active': _active,
      if (context.read<Session>().hasModule('etiquettes')) 'plu': t('plu'),
    });
    if (mounted) Navigator.pop(context, saved);
  }

  @override
  Widget build(BuildContext context) {
    final numFmt = [FilteringTextInputFormatter.allow(RegExp(r'[0-9.,]'))];
    final session = context.read<Session>();
    return Form(
      key: _key,
      child: ListView(padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), shrinkWrap: true, children: [
        Field(
          label: tr('Type'),
          child: SegmentedButton<String>(
            segments: [ButtonSegment(value: 'product', label: Text(tr('Produit stocké'))), ButtonSegment(value: 'service', label: Text(tr('Service')))],
            selected: {_type},
            onSelectionChanged: (s) => setState(() => _type = s.first),
          ),
        ),
        Field(label: tr('Désignation'), child: TextFormField(controller: _c['name'], autofocus: true, validator: (v) => (v ?? '').trim().isEmpty ? 'Obligatoire' : null)),
        Row(children: [
          Expanded(child: Field(label: tr('Référence'), child: TextFormField(controller: _c['sku']))),
          const SizedBox(width: 12),
          Expanded(child: Field(label: tr('Code-barres'), child: TextFormField(controller: _c['barcode']))),
        ]),
        Row(children: [
          Expanded(child: Field(label: tr('Prix de vente HT'), child: TextFormField(controller: _c['sale_price'], inputFormatters: numFmt, keyboardType: const TextInputType.numberWithOptions(decimal: true)))),
          const SizedBox(width: 12),
          Expanded(child: Field(label: tr('Prix d\'achat HT'), child: TextFormField(controller: _c['purchase_price'], inputFormatters: numFmt, keyboardType: const TextInputType.numberWithOptions(decimal: true)))),
        ]),
        Row(children: [
          Expanded(child: Field(label: tr('TVA (%)'), child: TextFormField(controller: _c['tax_rate'], inputFormatters: numFmt))),
          const SizedBox(width: 12),
          Expanded(
            child: Field(
              label: tr('Unité'),
              child: DropdownButtonFormField<String>(
                initialValue: _unit,
                items: [for (final u in units) DropdownMenuItem(value: u, child: Text(tr(u)))],
                onChanged: (v) => setState(() => _unit = v!),
              ),
            ),
          ),
        ]),
        if (session.hasModule('variantes') && !_isVariant)
          SwitchListTile(
            contentPadding: EdgeInsets.zero,
            title: Text(tr('Modèle à variantes')),
            subtitle: Text(tr('Tailles, couleurs… : le modèle ne se vend pas, ses variantes oui.')),
            value: _isTemplate,
            onChanged: (v) => setState(() => _isTemplate = v),
          ),
        if (_isVariant)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Text(tr('Variante de « ${LocalDb.get('products', '${widget.product!['parent_id']}')?['name'] ?? 'modèle'} » : ${((widget.product!['variant_values'] as Map?) ?? const {}).entries.map((e) => '${e.key} ${e.value}').join(', ')}'),
                style: TextStyle(color: Theme.of(context).hintColor)),
          ),
        if (_isTemplate) ...[
          VariantAttributesEditor(attributes: _attrs, extras: _extras),
          if (widget.product != null) ...[const Divider(height: 24), VariantsPanel(template: widget.product!)],
          const Divider(height: 24),
        ],
        if (_type == 'product' && !_isTemplate) ...[
          SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Suivre le stock')), value: _trackStock, onChanged: (v) => setState(() => _trackStock = v)),
          if (_trackStock) Field(label: tr('Seuil d\'alerte de stock'), child: TextFormField(controller: _c['min_stock'], inputFormatters: numFmt)),
          if (_trackStock && session.hasModule('lots')) ...[
            Field(
              label: tr('Traçabilité'),
              child: SegmentedButton<String>(
                segments: [
                  ButtonSegment(value: 'none', label: Text(tr('Aucune'))),
                  ButtonSegment(value: 'lot', label: Text(tr('Par lot'))),
                  ButtonSegment(value: 'serial', label: Text(tr('N° de série'))),
                ],
                selected: {_tracking},
                onSelectionChanged: (s) => setState(() => _tracking = s.first),
              ),
            ),
            if (_tracking != 'none')
              SwitchListTile(
                contentPadding: EdgeInsets.zero,
                title: Text(tr('Date de péremption')),
                subtitle: Text(tr('Sortie FEFO (premier périmé, premier sorti), alertes et blocage des lots périmés.')),
                value: _useExpiry,
                onChanged: (v) => setState(() => _useExpiry = v),
              ),
            if (_tracking != 'none' && _useExpiry)
              Row(children: [
                Expanded(child: Field(label: tr('Durée de vie (jours)'), child: TextFormField(controller: _c['shelf_life_days'], inputFormatters: [FilteringTextInputFormatter.digitsOnly], decoration: InputDecoration(helperText: tr('Péremption calculée à la réception'))))),
                const SizedBox(width: 12),
                Expanded(child: Field(label: tr('Alerte avant péremption (jours)'), child: TextFormField(controller: _c['expiry_alert_days'], inputFormatters: [FilteringTextInputFormatter.digitsOnly], decoration: InputDecoration(helperText: tr('Vide = réglage général'))))),
              ]),
          ],
        ],
        if (session.hasModule('tarifs') && !_isTemplate) ...[
          const SizedBox(height: 8),
          PackagingsEditor(items: _packagings, unit: _unit),
          const SizedBox(height: 8),
        ],
        if (_unit == 'kg' && context.read<Session>().hasModule('etiquettes'))
          Field(
            label: tr('Code balance (5 chiffres, articles pesés)'),
            child: TextFormField(
              controller: _c['plu'],
              maxLength: 5,
              inputFormatters: [FilteringTextInputFormatter.digitsOnly],
              validator: (v) => (v ?? '').isEmpty || v!.length == 5 ? null : '5 chiffres',
              decoration: InputDecoration(helperText: tr('La caisse lit les étiquettes de pesée (21 + code + poids) et ajoute le bon poids.')),
            ),
          ),
        Field(label: tr('Description'), child: TextFormField(controller: _c['description'], maxLines: 2)),
        if (context.read<Session>().hasModule('champs')) CustomFieldsEditor(entity: 'products', values: _custom, creating: widget.product == null),
        SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Actif (proposé à la vente)')), value: _active, onChanged: (v) => setState(() => _active = v)),
        const SizedBox(height: 8),
        Row(children: [
          if (widget.product != null && context.read<Session>().hasModule('etiquettes') && (context.read<Session>().can('etiquettes.print') || context.read<Session>().can('etiquettes.manage')))
            OutlinedButton.icon(
              onPressed: () {
                final p = LocalDb.get('products', '${widget.product!['id']}') ?? widget.product!;
                final nav = Navigator.of(context);
                nav.pop();
                nav.push(labelsRoute([LabelItem(Map<String, dynamic>.from(p))]));
              },
              icon: const Icon(Icons.qr_code_2),
              label: Text(tr('Étiquettes')),
            ),
          const Spacer(),
          SaveButton(onPressed: _save, child: Text(tr('Enregistrer'))),
        ]),
      ]),
    );
  }
}
