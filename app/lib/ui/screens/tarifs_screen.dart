import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../../core/local_db.dart';
import '../../core/pricing.dart';
import '../../core/sync_engine.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/filter_bar.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

String _n(dynamic v) {
  if (v == null || '$v'.isEmpty) return '';
  final d = toDouble(v);
  return d == d.roundToDouble() ? '${d.toInt()}' : '$d';
}

List<Map<String, dynamic>> _sellable() =>
    LocalDb.all('products').where((p) => p['active'] != false && p['is_template'] != true).toList()..sort((a, b) => '${a['name']}'.toLowerCase().compareTo('${b['name']}'.toLowerCase()));

/// Listes de prix (module tarifs) : grossistes, revendeurs, promotions, remises par quantité.
class TarifsScreen extends StatefulWidget {
  const TarifsScreen({super.key});

  @override
  State<TarifsScreen> createState() => _TarifsScreenState();
}

class _TarifsScreenState extends State<TarifsScreen> {
  String _q = '';
  String _status = '';
  Map<String, String?> _f = {};
  FilterPeriod? _period;

  static const _statuses = {'': 'Toutes', 'active': 'Actives', 'ended': 'Terminées', 'inactive': 'Inactives'};

  static bool _ended(Map<String, dynamic> l) => l['end_date'] != null && '${l['end_date']}'.compareTo(isoDate(DateTime.now())) < 0;

  static bool _statusOk(Map<String, dynamic> l, String st) => switch (st) {
        'active' => l['active'] != false && !_ended(l),
        'ended' => _ended(l),
        'inactive' => l['active'] == false,
        _ => true,
      };

  static List<Map<String, dynamic>> _rules(Map<String, dynamic> l) => [for (final i in (l['items'] as List? ?? const [])) if (i is Map) Map<String, dynamic>.from(i)];

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final canEdit = session.can('tarifs.manage');
    final narrow = MediaQuery.of(context).size.width < 700;
    return Column(
      children: [
        PageHeader(
          title: tr('Listes de prix'),
          subtitle: tr('Prix par client ou par période, remises par catégorie et par quantité'),
          actions: [if (canEdit) FilledButton.icon(onPressed: () => editPriceList(context), icon: const Icon(Icons.add), label: Text(tr('Nouvelle liste')))],
        ),
        Expanded(
          child: ValueListenableBuilder(
            valueListenable: LocalDb.listen('pl_lists'),
            builder: (context, _, _) {
              final lists = LocalDb.all('pl_lists')
                ..sort((a, b) {
                  final c = toDouble(a['sort']).compareTo(toDouble(b['sort']));
                  return c != 0 ? c : '${a['name']}'.compareTo('${b['name']}');
                });
              final partners = LocalDb.hasEntity('partners') ? LocalDb.all('partners') : const <Map<String, dynamic>>[];
              final products = {for (final p in LocalDb.all('products')) '${p['id']}': '${p['name']}'};
              final cats = {for (final c in LocalDb.all('product_categories')) '${c['id']}': '${c['name']}'};
              final allRules = [for (final l in lists) ..._rules(l)];
              final clientLists = {for (final p in partners) if (p['price_list_id'] != null) '${p['id']}': '${p['price_list_id']}'};
              final base = lists.where((l) {
                if (_q.isNotEmpty && !'${l['name']} ${l['notes'] ?? ''}'.toLowerCase().contains(_q)) return false;
                final cl = _f['client'], pr = _f['product'], c = _f['category'];
                if (cl != null && clientLists[cl] != '${l['id']}') return false;
                if (pr != null && !_rules(l).any((i) => '${i['product_id']}' == pr)) return false;
                if (c != null && !_rules(l).any((i) => '${i['category_id']}' == c)) return false;
                if (_period != null) {
                  final st = DateTime.tryParse('${l['start_date'] ?? ''}'), en = DateTime.tryParse('${l['end_date'] ?? ''}');
                  if (st != null && st.isAfter(_period!.end)) return false;
                  if (en != null && en.isBefore(_period!.start)) return false;
                }
                return true;
              }).toList();
              final shown = base.where((l) => _statusOk(l, _status)).toList();
              return ListView(
                padding: EdgeInsets.fromLTRB(narrow ? 16 : 24, 0, narrow ? 16 : 24, 24),
                children: [
                  const _Simulator(),
                  const SizedBox(height: 12),
                  if (lists.isNotEmpty)
                    FilterBar(
                      padding: const EdgeInsets.only(bottom: 12),
                      search: _q,
                      searchHint: 'Nom de la liste…',
                      onSearch: (v) => setState(() => _q = v.toLowerCase()),
                      statuses: _statuses,
                      status: _status,
                      onStatus: (v) => setState(() => _status = v),
                      statusCounts: {for (final k in _statuses.keys) k: base.where((l) => _statusOk(l, k)).length},
                      filters: [
                        FilterSpec('client', 'Client', filterOptions(partners.where((p) => p['price_list_id'] != null), 'id', label: (p) => '${p['name']}'), icon: Icons.person_outline),
                        FilterSpec('product', 'Article', filterOptions(allRules.where((i) => products.containsKey('${i['product_id']}')), 'product_id', label: (i) => products['${i['product_id']}']!), icon: Icons.inventory_2_outlined),
                        FilterSpec('category', 'Catégorie', filterOptions(allRules.where((i) => cats.containsKey('${i['category_id']}')), 'category_id', label: (i) => cats['${i['category_id']}']!), icon: Icons.category_outlined),
                      ],
                      values: _f,
                      onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
                      showPeriod: true,
                      period: _period,
                      onPeriod: (v) => setState(() => _period = v),
                      count: shown.length,
                      countLabel: 'liste',
                      onReset: () => setState(() => (_q = '', _status = '', _f = {}, _period = null)),
                    ),
                  if (lists.isEmpty)
                    EmptyState(icon: Icons.sell_outlined, title: tr('Aucune liste de prix'), message: tr('Créez une liste « Grossistes » avec −10 % dès 10 cartons, ou une promotion datée.'))
                  else if (shown.isEmpty)
                    EmptyState(icon: Icons.search_off, title: tr('Aucun résultat'))
                  else
                    for (final l in shown)
                      Card(
                        child: ListTile(
                          onTap: canEdit ? () => editPriceList(context, l) : null,
                          leading: CircleAvatar(
                            backgroundColor: YColors.blue.withValues(alpha: .12),
                            child: Icon(l['is_default'] == true ? Icons.star : Icons.sell_outlined, color: YColors.blue),
                          ),
                          title: Row(
                            children: [
                              Flexible(
                                child: Text(
                                  tr('${l['name']}'),
                                  style: TextStyle(fontWeight: FontWeight.w700, color: l['active'] == false ? Colors.grey : null),
                                ),
                              ),
                              if (l['is_default'] == true)
                                Padding(
                                  padding: EdgeInsets.only(left: 8),
                                  child: StatusChip(tr('Par défaut'), color: YColors.blue),
                                ),
                              if (l['active'] == false) Padding(padding: EdgeInsets.only(left: 8), child: StatusChip(tr('Inactive'))),
                              if (l['end_date'] != null && '${l['end_date']}'.compareTo(isoDate(DateTime.now())) < 0)
                                Padding(
                                  padding: EdgeInsets.only(left: 8),
                                  child: StatusChip(tr('Terminée'), color: YColors.warning),
                                ),
                            ],
                          ),
                          subtitle: Text(
                            tr([
                              '${(l['items'] as List?)?.length ?? 0} règle(s)',
                              if (l['start_date'] != null || l['end_date'] != null)
                                'du ${l['start_date'] == null ? '…' : dateFr(l['start_date'])} au ${l['end_date'] == null ? '…' : dateFr(l['end_date'])}',
                              '${partners.where((p) => p['price_list_id'] == l['id']).length} client(s)',
                            ].join(' · ')),
                          ),
                          trailing: l['_local'] == true ? const Icon(Icons.cloud_upload_outlined, size: 18, color: YColors.warning) : const Icon(Icons.chevron_right),
                        ),
                      ),
                ],
              );
            },
          ),
        ),
      ],
    );
  }
}

/// Simulateur : prix appliqué à un client pour un article et une quantité.
class _Simulator extends StatefulWidget {
  const _Simulator();

  @override
  State<_Simulator> createState() => _SimulatorState();
}

class _SimulatorState extends State<_Simulator> {
  String? _partner;
  String? _product;
  String? _packaging;
  double _qty = 1;

  @override
  Widget build(BuildContext context) {
    final currency = context.read<Session>().currency;
    final clients = LocalDb.all('partners').where((p) => p['type'] != 'supplier' && p['active'] != false).toList()..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));
    final products = _sellable();
    final product = _product == null ? null : LocalDb.get('products', _product);
    final pks = packagingsOf(product);
    final pk = pks.where((e) => e['name'] == _packaging).firstOrNull;
    final r = product == null ? null : resolvePrice(partnerId: _partner, product: product, quantity: _qty, packaging: pk);
    final base = product == null ? 0.0 : basePrice(product, pk);
    final net = r == null ? 0.0 : r.unitPrice * (1 - r.discount / 100);
    return SectionCard(
      title: tr('Simulateur de prix'),
      child: Wrap(
        spacing: 12,
        runSpacing: 8,
        crossAxisAlignment: WrapCrossAlignment.end,
        children: [
          SizedBox(
            width: 220,
            child: DropdownButtonFormField<String?>(
              initialValue: _partner,
              isExpanded: true,
              decoration: InputDecoration(labelText: tr('Client')),
              items: [
                DropdownMenuItem(value: null, child: Text(tr('Client comptoir'))),
                for (final c in clients)
                  DropdownMenuItem(
                    value: '${c['id']}',
                    child: Text(tr('${c['name']}'), overflow: TextOverflow.ellipsis),
                  ),
              ],
              onChanged: (v) => setState(() => _partner = v),
            ),
          ),
          SizedBox(
            width: 240,
            child: DropdownButtonFormField<String?>(
              initialValue: _product,
              isExpanded: true,
              decoration: InputDecoration(labelText: tr('Article')),
              items: [
                for (final p in products)
                  DropdownMenuItem(
                    value: '${p['id']}',
                    child: Text(tr('${p['name']}'), overflow: TextOverflow.ellipsis),
                  ),
              ],
              onChanged: (v) => setState(() {
                _product = v;
                _packaging = null;
              }),
            ),
          ),
          if (pks.isNotEmpty)
            SizedBox(
              width: 150,
              child: DropdownButtonFormField<String?>(
                key: ValueKey('pk-$_product'),
                initialValue: _packaging,
                isExpanded: true,
                decoration: InputDecoration(labelText: tr('Unité')),
                items: [
                  DropdownMenuItem(value: null, child: Text(tr('${product?['unit'] ?? 'unité'}'))),
                  for (final e in pks) DropdownMenuItem(value: '${e['name']}', child: Text(tr('${e['name']} ×${_n(e['factor'])}'))),
                ],
                onChanged: (v) => setState(() => _packaging = v),
              ),
            ),
          SizedBox(
            width: 90,
            child: TextFormField(
              initialValue: '1',
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              decoration: InputDecoration(labelText: tr('Quantité')),
              onChanged: (v) => setState(() => _qty = toDouble(v) <= 0 ? 1 : toDouble(v)),
            ),
          ),
          if (r != null)
            Padding(
              padding: const EdgeInsets.only(bottom: 4),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(tr('${money(net, currency)} HT'), style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
                      if ((net - base).abs() > 0.004) ...[
                        const SizedBox(width: 8),
                        Text(
                          tr(money(base, currency)),
                          style: TextStyle(decoration: TextDecoration.lineThrough, color: Theme.of(context).hintColor),
                        ),
                      ],
                    ],
                  ),
                  Text(tr(r.label ?? 'Prix catalogue'), style: TextStyle(fontSize: 12, color: r.label == null ? Theme.of(context).hintColor : YColors.success)),
                ],
              ),
            ),
        ],
      ),
    );
  }
}

Future<void> editPriceList(BuildContext context, [Map<String, dynamic>? list]) => showFormDialog(
  context,
  title: list == null ? 'Nouvelle liste de prix' : 'Liste de prix',
  maxWidth: 760,
  builder: (_) => _PriceListForm(list: list),
);

class _PriceListForm extends StatefulWidget {
  const _PriceListForm({this.list});

  final Map<String, dynamic>? list;

  @override
  State<_PriceListForm> createState() => _PriceListFormState();
}

class _PriceListFormState extends State<_PriceListForm> {
  late final _name = TextEditingController(text: '${widget.list?['name'] ?? ''}');
  late final _notes = TextEditingController(text: '${widget.list?['notes'] ?? ''}');
  late bool _default = widget.list?['is_default'] == true;
  late bool _active = widget.list?['active'] != false;
  late String? _start = widget.list?['start_date'] as String?;
  late String? _end = widget.list?['end_date'] as String?;
  late final List<Map<String, dynamic>> _items = [for (final i in (widget.list?['items'] as List? ?? const [])) asMap(i)];
  int _rev = 0;

  Future<void> _save() async {
    if (_name.text.trim().isEmpty) return toast(context, tr('Donnez un nom à la liste.'), error: true);
    for (var i = 0; i < _items.length; i++) {
      final it = _items[i];
      if (it['scope'] == 'product' && it['product_id'] == null) return toast(context, tr('Règle ${i + 1} : choisissez l\'article.'), error: true);
      if (it['scope'] == 'category' && it['category_id'] == null) return toast(context, tr('Règle ${i + 1} : choisissez la catégorie.'), error: true);
      if (it['mode'] == 'price' && it['scope'] != 'product') return toast(context, tr('Règle ${i + 1} : un prix fixe s\'applique à un article ; pour une catégorie, indiquez une remise.'), error: true);
      if (it['mode'] == 'discount' && (toDouble(it['discount']) <= 0 || toDouble(it['discount']) > 100)) return toast(context, tr('Règle ${i + 1} : remise entre 0 et 100 %.'), error: true);
    }
    if (_start != null && _end != null && _end!.compareTo(_start!) < 0) return toast(context, tr('La date de fin doit suivre la date de début.'), error: true);
    final sync = context.read<SyncEngine>();
    if (_default) {
      // Une seule liste par défaut (le serveur fait de même)
      for (final l in LocalDb.all('pl_lists').where((l) => l['is_default'] == true && l['id'] != widget.list?['id'])) {
        await LocalDb.put('pl_lists', {...l, 'is_default': false});
      }
    }
    await sync.save('pl_lists', {
      if (widget.list != null) 'id': widget.list!['id'],
      'name': _name.text.trim(),
      'is_default': _default,
      'active': _active,
      'start_date': _start,
      'end_date': _end,
      'notes': _notes.text.trim().isEmpty ? null : _notes.text.trim(),
      'items': [
        for (final it in _items)
          {
            'scope': it['scope'],
            'min_qty': toDouble(it['min_qty']),
            'mode': it['mode'],
            if (it['scope'] == 'product') 'product_id': it['product_id'],
            if (it['scope'] == 'product' && it['packaging'] != null) 'packaging': it['packaging'],
            if (it['scope'] == 'category') 'category_id': it['category_id'],
            if (it['mode'] == 'price') 'price': toDouble(it['price']) else 'discount': toDouble(it['discount']),
          },
      ],
    });
    if (mounted) Navigator.pop(context);
  }

  Future<void> _delete() async {
    if (!await confirm(context, tr('Supprimer cette liste ?'), tr('Les clients qui l\'utilisent repasseront sur la liste par défaut.'), ok: tr('Supprimer'), danger: true)) return;
    if (!mounted) return;
    await context.read<SyncEngine>().remove('pl_lists', widget.list!['id']);
    if (mounted) Navigator.pop(context);
  }

  Future<void> _pickDate(bool start) async {
    final cur = DateTime.tryParse((start ? _start : _end) ?? '') ?? DateTime.now();
    final d = await pickDate(context, initialDate: cur, firstDate: DateTime(2020), lastDate: DateTime(2100));
    if (d != null) setState(() => start ? _start = isoDate(d) : _end = isoDate(d));
  }

  @override
  Widget build(BuildContext context) {
    final cats = LocalDb.hasEntity('product_categories') ? (LocalDb.all('product_categories')..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'))) : const <Map<String, dynamic>>[];
    final products = _sellable();
    final hint = Theme.of(context).hintColor;
    return ListView(
      padding: const EdgeInsets.fromLTRB(24, 8, 24, 24),
      shrinkWrap: true,
      children: [
        Field(
          label: tr('Nom'),
          child: TextField(
            controller: _name,
            autofocus: widget.list == null,
            decoration: InputDecoration(hintText: tr('Grossistes, Revendeurs, Promo Tabaski…')),
          ),
        ),
        Wrap(
          spacing: 12,
          runSpacing: 8,
          children: [
            SizedBox(
              width: 170,
              child: Field(
                label: tr('Début (facultatif)'),
                child: OutlinedButton(onPressed: () => _pickDate(true), child: Text(tr(_start == null ? '—' : dateFr(_start)))),
              ),
            ),
            SizedBox(
              width: 170,
              child: Field(
                label: tr('Fin (facultatif)'),
                child: OutlinedButton(onPressed: () => _pickDate(false), child: Text(tr(_end == null ? '—' : dateFr(_end)))),
              ),
            ),
            if (_start != null || _end != null)
              Padding(
                padding: const EdgeInsets.only(top: 22),
                child: TextButton(onPressed: () => setState(() => _start = _end = null), child: Text(tr('Sans dates'))),
              ),
          ],
        ),
        SwitchListTile(
          contentPadding: EdgeInsets.zero,
          title: Text(tr('Liste par défaut')),
          subtitle: Text(tr('S\'applique aux clients sans liste attitrée (promotion générale, par exemple).')),
          value: _default,
          onChanged: (v) => setState(() => _default = v),
        ),
        SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Active')), value: _active, onChanged: (v) => setState(() => _active = v)),
        const Divider(),
        Text(tr('Règles'), style: Theme.of(context).textTheme.titleSmall),
        Text(tr('La règle la plus précise l\'emporte (article › catégorie › tout le catalogue), puis le palier de quantité le plus haut atteint.'), style: TextStyle(fontSize: 12, color: hint)),
        const SizedBox(height: 8),
        for (var i = 0; i < _items.length; i++) _itemRow(i, cats, products),
        Align(
          alignment: Alignment.centerLeft,
          child: TextButton.icon(
            onPressed: () => setState(() {
              _items.add({'scope': 'product', 'mode': 'discount', 'min_qty': 0, 'discount': 5});
              _rev++;
            }),
            icon: const Icon(Icons.add),
            label: Text(tr('Ajouter une règle')),
          ),
        ),
        Field(
          label: tr('Notes'),
          child: TextField(controller: _notes, maxLines: 2),
        ),
        const SizedBox(height: 8),
        Row(
          children: [
            if (widget.list != null)
              TextButton(
                onPressed: _delete,
                style: TextButton.styleFrom(foregroundColor: YColors.danger),
                child: Text(tr('Supprimer')),
              ),
            const Spacer(),
            SaveButton(onPressed: _save, child: Text(tr('Enregistrer'))),
          ],
        ),
      ],
    );
  }

  Widget _itemRow(int i, List<Map<String, dynamic>> cats, List<Map<String, dynamic>> products) {
    final it = _items[i];
    final product = it['product_id'] == null ? null : LocalDb.get('products', '${it['product_id']}');
    final pks = packagingsOf(product);
    final k = 'r$_rev-$i';
    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(
        border: Border.all(color: YColors.line),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Column(
        children: [
          Row(
            children: [
              SizedBox(
                width: 175,
                child: DropdownButtonFormField<String>(
                  key: ValueKey('$k-scope'),
                  initialValue: it['scope'] as String,
                  isExpanded: true,
                  decoration: InputDecoration(isDense: true, labelText: tr('Porte sur')),
                  items: [
                    DropdownMenuItem(value: 'product', child: Text(tr('Un article'))),
                    DropdownMenuItem(value: 'category', child: Text(tr('Une catégorie'))),
                    DropdownMenuItem(value: 'all', child: Text(tr('Tout'))),
                  ],
                  onChanged: (v) => setState(() {
                    it['scope'] = v;
                    if (v != 'product') it['mode'] = 'discount';
                  }),
                ),
              ),
              const SizedBox(width: 8),
              if (it['scope'] == 'product')
                Expanded(
                  child: DropdownButtonFormField<String?>(
                    key: ValueKey('$k-p'),
                    initialValue: products.any((p) => p['id'] == it['product_id']) ? it['product_id'] as String? : null,
                    isExpanded: true,
                    decoration: InputDecoration(isDense: true, labelText: tr('Article')),
                    items: [
                      for (final p in products)
                        DropdownMenuItem(
                          value: '${p['id']}',
                          child: Text(tr('${p['name']}'), overflow: TextOverflow.ellipsis),
                        ),
                    ],
                    onChanged: (v) => setState(() {
                      it['product_id'] = v;
                      it.remove('packaging');
                    }),
                  ),
                ),
              if (it['scope'] == 'product' && pks.isNotEmpty) const SizedBox(width: 8),
              if (it['scope'] == 'product' && pks.isNotEmpty)
                SizedBox(
                  width: 170,
                  child: DropdownButtonFormField<String?>(
                    key: ValueKey('$k-pk-${it['product_id']}'),
                    initialValue: pks.any((e) => e['name'] == it['packaging']) ? it['packaging'] as String? : null,
                    isExpanded: true,
                    decoration: InputDecoration(isDense: true, labelText: tr('Conditionnement')),
                    items: [
                      DropdownMenuItem(value: null, child: Text(tr('Tous'))),
                      for (final e in pks) DropdownMenuItem(value: '${e['name']}', child: Text(tr('${e['name']}'))),
                    ],
                    onChanged: (v) => setState(() => v == null ? it.remove('packaging') : it['packaging'] = v),
                  ),
                ),
              if (it['scope'] == 'category')
                Expanded(
                  child: DropdownButtonFormField<String?>(
                    key: ValueKey('$k-c'),
                    initialValue: cats.any((c) => c['id'] == it['category_id']) ? it['category_id'] as String? : null,
                    isExpanded: true,
                    decoration: InputDecoration(isDense: true, labelText: tr('Catégorie')),
                    items: [
                      for (final c in cats)
                        DropdownMenuItem(
                          value: '${c['id']}',
                          child: Text(tr('${c['name']}'), overflow: TextOverflow.ellipsis),
                        ),
                    ],
                    onChanged: (v) => setState(() => it['category_id'] = v),
                  ),
                ),
              if (it['scope'] == 'all') const Spacer(),
              IconButton(
                onPressed: () => setState(() {
                  _items.removeAt(i);
                  _rev++;
                }),
                icon: const Icon(Icons.close, size: 18),
              ),
            ],
          ),
          const SizedBox(height: 8),
          Row(
            children: [
              SizedBox(
                width: 110,
                child: TextFormField(
                  key: ValueKey('$k-min'),
                  initialValue: _n(it['min_qty']) == '0' ? '' : _n(it['min_qty']),
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                  inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9.,]'))],
                  decoration: InputDecoration(isDense: true, labelText: tr('À partir de'), hintText: '1', floatingLabelBehavior: FloatingLabelBehavior.always),
                  onChanged: (v) => it['min_qty'] = toDouble(v),
                ),
              ),
              const SizedBox(width: 8),
              if (it['scope'] == 'product') ...[
                SegmentedButton<String>(
                  style: const ButtonStyle(visualDensity: VisualDensity.compact),
                  segments: [
                    ButtonSegment(value: 'price', label: Text(tr('Prix'))),
                    ButtonSegment(value: 'discount', label: Text(tr('Remise'))),
                  ],
                  selected: {'${it['mode']}'},
                  onSelectionChanged: (s) => setState(() => it['mode'] = s.first),
                ),
                const SizedBox(width: 8),
              ],
              SizedBox(
                width: 110,
                child: TextFormField(
                  key: ValueKey('$k-v-${it['mode']}'),
                  initialValue: _n(it['mode'] == 'price' ? it['price'] : it['discount']),
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                  inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9.,]'))],
                  decoration: InputDecoration(isDense: true, labelText: trn(it['mode'] == 'price' ? 'Prix HT' : 'Remise'), suffixText: trn(it['mode'] == 'price' ? null : '%')),
                  onChanged: (v) => it[it['mode'] == 'price' ? 'price' : 'discount'] = toDouble(v),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}
