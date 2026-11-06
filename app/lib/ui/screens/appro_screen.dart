import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart';
import 'package:printing/printing.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/local_db.dart';
import '../../files/save_file.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/filter_bar.dart';
import '../widgets/report_charts.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

import 'package:yomara_erp/core/safe_launch.dart';
// ---------------------------------------------------------------------------------------------- outils

List<Map<String, dynamic>> _suppliers() =>
    LocalDb.all('partners').where((p) => p['deleted_at'] == null && ['supplier', 'both'].contains(p['type'])).map((p) => Map<String, dynamic>.from(p)).toList()..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));

List<Map<String, dynamic>> _products() => LocalDb.all('products').where((p) => p['deleted_at'] == null && p['is_template'] != true).map((p) => Map<String, dynamic>.from(p)).toList();

final _num = [FilteringTextInputFormatter.allow(RegExp(r'[0-9.,]'))];

String _q(dynamic v) {
  final d = toDouble(v);
  return d == d.roundToDouble() ? '${d.round()}' : d.toStringAsFixed(d * 10 == (d * 10).roundToDouble() ? 1 : 2);
}

Future<void> _openPdf(BuildContext context, String path, String name) async {
  try {
    final bytes = await context.read<Session>().api.download(path);
    await Printing.layoutPdf(name: name, onLayout: (_) async => Uint8List.fromList(bytes));
  } catch (e) {
    if (context.mounted) toast(context, errorText(e), error: true);
  }
}

Future<void> _saveXlsx(BuildContext context, String path, String name) async {
  try {
    final bytes = await context.read<Session>().api.download(path);
    final where = await saveFile('$name.xlsx', bytes, mime: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    if (context.mounted) toast(context, tr('Fichier Excel enregistré${where.isEmpty ? '' : ' : $where'}'));
  } catch (e) {
    if (context.mounted) toast(context, errorText(e), error: true);
  }
}

Widget _dateButton(BuildContext context, String label, String? value, ValueChanged<String?> on, {bool clear = true}) => OutlinedButton.icon(
      onPressed: () async {
        final d = await pickDate(context, firstDate: DateTime(2020), lastDate: DateTime(2100), initialDate: DateTime.tryParse(value ?? '') ?? DateTime.now());
        if (d != null) on(isoDate(d));
      },
      onLongPress: clear ? () => on(null) : null,
      icon: const Icon(Icons.event, size: 18),
      label: Text(tr(value == null ? label : '$label : ${dateFr(value)}')),
    );

Widget _supplierPicker(String? value, ValueChanged<String?> on, {String label = 'Fournisseur', String? name}) {
  final list = _suppliers();
  if (value != null && name != null && !list.any((p) => p['id'] == value)) list.insert(0, {'id': value, 'name': name});
  return DropdownButtonFormField<String>(
    isExpanded: true,
    initialValue: list.any((p) => p['id'] == value) ? value : null,
    decoration: InputDecoration(labelText: trn(label)),
    items: [for (final p in list) DropdownMenuItem(value: '${p['id']}', child: Text(tr('${p['name']}${p['country'] != null ? ' (${p['country']})' : ''}'), overflow: TextOverflow.ellipsis))],
    onChanged: on,
  );
}

Widget _kpi(BuildContext c, String l, String v, IconData ic, Color col, {String? sub, VoidCallback? onTap}) => KpiBox(
      width: 250,
      child: StatTile(label: l, value: v, detail: sub, icon: ic, color: col, onTap: onTap),
    );

/// Achats techniques : importations (coût de revient), cahiers des charges, comparatifs, locations, fournisseurs.
class ApproScreen extends StatefulWidget {
  const ApproScreen({super.key});

  @override
  State<ApproScreen> createState() => _ApproScreenState();
}

class _ApproScreenState extends State<ApproScreen> with SingleTickerProviderStateMixin {
  Map<String, dynamic>? _meta;
  Object? _error;
  int _reload = 0;
  late final TabController _tc = TabController(length: 6, vsync: this);
  late final bool _manage = context.read<Session>().can('appro.manage');

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('appro/meta');
      if (mounted) setState(() => (_meta = asMap(r['data']), _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  void _refresh() => setState(() => _reload++);

  @override
  Widget build(BuildContext context) {
    if (_error != null && _meta == null) return OnlineOnlyNotice(error: _error!, onRetry: _load);
    if (_meta == null) return const Center(child: CircularProgressIndicator());
    final m = _meta!;
    return Column(children: [
      PageHeader(title: tr('Importations, comparatifs & locations'), subtitle: tr('Coût de revient des importations, cahiers des charges, comparatifs de prix, locations et services externes, évaluation des fournisseurs')),
      TabBar(controller: _tc, isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [
        Tab(text: tr('Tableau de bord')), Tab(text: tr('Importations')), Tab(text: tr('Comparatifs')), Tab(text: tr('Cahiers des charges')), Tab(text: tr('Locations & services')), Tab(text: tr('Fournisseurs')),
      ]),
      Expanded(
        child: TabBarView(controller: _tc, physics: const NeverScrollableScrollPhysics(), children: [
          _ApproDashboard(key: ValueKey('d$_reload'), meta: m, onTab: (i) => _tc.animateTo(i), onChanged: _refresh),
          _ImportsTab(key: ValueKey('i$_reload'), meta: m, manage: _manage, onChanged: _refresh),
          _ComparisonsTab(key: ValueKey('c$_reload'), meta: m, manage: _manage, onChanged: _refresh),
          _SpecsTab(key: ValueKey('s$_reload'), meta: m, manage: _manage, onChanged: _refresh),
          _RentalsTab(key: ValueKey('r$_reload'), meta: m, manage: _manage, onChanged: _refresh),
          _SuppliersTab(key: ValueKey('f$_reload')),
        ]),
      ),
    ]);
  }
}

/// Liste générique chargée depuis l'API.
class _ApiList extends StatefulWidget {
  const _ApiList({required this.path, this.query, required this.builder});

  final String path;
  final Map<String, String>? query;
  final Widget Function(BuildContext, List<Map<String, dynamic>>, Future<void> Function()) builder;

  @override
  State<_ApiList> createState() => _ApiListState();
}

class _ApiListState extends State<_ApiList> {
  List<Map<String, dynamic>>? _rows;
  Object? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(covariant _ApiList old) {
    super.didUpdateWidget(old);
    if (old.query.toString() != widget.query.toString()) {
      // On garde la liste affichée pendant le rechargement (la barre de filtres reste en place).
      _load();
    }
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get(widget.path, query: widget.query);
      if (mounted) setState(() => (_rows = [for (final x in r['data'] as List) asMap(x)], _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_error != null && _rows == null) return OnlineOnlyNotice(error: _error!, onRetry: _load);
    if (_rows == null) return const LoadingSkeleton();
    return RefreshIndicator(onRefresh: _load, child: widget.builder(context, _rows!, _load));
  }
}

/// Statut d'une ligne d'API : valeur → libellé (status_label fourni par le serveur).
Map<String, String> _statusOptions(List<Map<String, dynamic>> rows, [Map<String, String> fallback = const {}]) =>
    {'': 'Tous', ...filterOptions(rows, 'status', label: (r) => '${r['status_label'] ?? fallback[r['status']] ?? r['status']}')};

Map<String, int> _statusCounts(Map<String, String> statuses, List<Map<String, dynamic>> base) =>
    {for (final k in statuses.keys) k: k.isEmpty ? base.length : base.where((r) => '${r['status']}' == k).length};

// ---------------------------------------------------------------------------------------------- tableau de bord

class _ApproDashboard extends StatelessWidget {
  const _ApproDashboard({super.key, required this.meta, required this.onTab, required this.onChanged});

  final Map<String, dynamic> meta;
  final void Function(int) onTab;
  final VoidCallback onChanged;

  @override
  Widget build(BuildContext context) {
    return FutureBuilder(
      future: context.read<Session>().api.get('appro/dashboard'),
      builder: (c, snap) {
        if (snap.hasError) return OnlineOnlyNotice(error: snap.error!, onRetry: onChanged);
        if (!snap.hasData) return const LoadingSkeleton();
        final d = asMap((snap.data as Map)['data']);
        final k = asMap(d['kpis']);
        final cur = context.watch<Session>().currency;
        final hint = Theme.of(c).hintColor;
        return ListView(padding: const EdgeInsets.all(16), children: [
          KpiWrap(children: [
            _kpi(c, 'Importations en cours', '${k['imports_open']}', Icons.directions_boat_outlined, YColors.blue, sub: '${money(toDouble(k['imports_value']), cur)} rendus dépôt', onTap: () => onTab(1)),
            _kpi(c, 'Arrivées en retard', '${k['imports_late']}', Icons.schedule, YColors.danger, onTap: () => onTab(1)),
            _kpi(c, 'Coefficient d\'approche moyen', k['coefficient'] == null ? '—' : NumberFormat('0.000', 'fr').format(toDouble(k['coefficient'])), Icons.calculate_outlined, YColors.night, sub: 'coût rendu / prix d\'achat'),
            _kpi(c, 'Comparatifs ouverts', '${k['comparisons_open']}', Icons.compare_arrows, const Color(0xFF8E44AD), sub: 'économie obtenue : ${money(toDouble(k['savings']), cur)}', onTap: () => onTab(2)),
            _kpi(c, 'Locations en cours', '${k['rentals_active']}', Icons.construction_outlined, YColors.success, sub: '${k['rentals_ending']} se terminent sous 7 jours', onTap: () => onTab(4)),
            _kpi(c, 'Locations dépassées', '${k['rentals_overdue']}', Icons.event_busy, YColors.danger, onTap: () => onTab(4)),
            _kpi(c, 'Coût des locations ce mois', money(toDouble(k['rentals_month']), cur), Icons.payments_outlined, YColors.warning),
          ]),
          const SizedBox(height: 12),
          Wrap(spacing: 12, runSpacing: 12, children: [
            SizedBox(
              width: 520,
              child: SectionCard(
                title: tr('Importations à suivre'),
                child: Column(children: [
                  for (final f in List<Map>.from(d['imports'] as List))
                    ListTile(
                      dense: true,
                      contentPadding: EdgeInsets.zero,
                      onTap: () => _openImport(context, meta, f['id'] as int).then((_) => onChanged()),
                      title: Text(tr('${f['number']} · ${f['title']}'), overflow: TextOverflow.ellipsis),
                      subtitle: Text(tr([if (f['partner'] != null) '${f['partner']}', if (f['eta'] != null) 'arrivée prévue ${dateFr(f['eta'])}'].join(' · ')), style: TextStyle(color: f['late'] == true ? YColors.danger : hint)),
                      trailing: StatusChip('${f['status_label']}', color: YColors.blue),
                    ),
                  if ((d['imports'] as List).isEmpty) Text(tr('Aucune importation en cours.'), style: TextStyle(color: hint)),
                ]),
              ),
            ),
            SizedBox(
              width: 520,
              child: SectionCard(
                title: tr('Locations et prestations en cours'),
                child: Column(children: [
                  for (final r in List<Map>.from(d['rentals'] as List))
                    ListTile(
                      dense: true,
                      contentPadding: EdgeInsets.zero,
                      title: Text(tr('${r['title']}'), overflow: TextOverflow.ellipsis),
                      subtitle: Text(tr('${r['partner'] ?? ''} · jusqu\'au ${dateFr(r['end_date'])}'), style: TextStyle(color: r['overdue'] == true ? YColors.danger : hint)),
                      trailing: Text(tr(money(toDouble(r['amount']), cur)), style: const TextStyle(fontWeight: FontWeight.w700)),
                    ),
                  if ((d['rentals'] as List).isEmpty) Text(tr('Aucune location en cours.'), style: TextStyle(color: hint)),
                ]),
              ),
            ),
            SizedBox(
              width: 520,
              child: SectionCard(
                title: tr('Principaux fournisseurs (12 mois)'),
                child: Column(children: [
                  for (final s in List<Map>.from(d['suppliers'] as List))
                    ListTile(
                      dense: true,
                      contentPadding: EdgeInsets.zero,
                      onTap: () => _openSupplier(context, '${s['partner_id']}'),
                      title: Text(tr('${s['name']}'), overflow: TextOverflow.ellipsis),
                      subtitle: Text(tr([if (s['lead_days'] != null) 'délai ${_q(s['lead_days'])} j', if (s['on_time'] != null) 'à l\'heure ${s['on_time']} %', if (s['score'] != null) 'note ${s['score']}/5'].join(' · '))),
                      trailing: Text(tr(money(toDouble(s['spend']), cur)), style: const TextStyle(fontWeight: FontWeight.w700)),
                    ),
                  if ((d['suppliers'] as List).isEmpty) Text(tr('Pas encore d\'achats.'), style: TextStyle(color: hint)),
                ]),
              ),
            ),
          ]),
        ]);
      },
    );
  }
}

// ---------------------------------------------------------------------------------------------- importations

Color _importColor(String s) => switch (s) { 'draft' => YColors.muted, 'received' || 'closed' => YColors.success, 'cancelled' => YColors.danger, 'customs' => YColors.warning, _ => YColors.blue };

class _ImportsTab extends StatefulWidget {
  const _ImportsTab({super.key, required this.meta, required this.manage, required this.onChanged});

  final Map<String, dynamic> meta;
  final bool manage;
  final VoidCallback onChanged;

  @override
  State<_ImportsTab> createState() => _ImportsTabState();
}

class _ImportsTabState extends State<_ImportsTab> {
  String _search = '';
  String _status = '';
  Map<String, String?> _f = {};
  FilterPeriod? _period;

  @override
  Widget build(BuildContext context) {
    final meta = widget.meta, manage = widget.manage;
    final cur = context.watch<Session>().currency;
    final hint = Theme.of(context).hintColor;
    return _ApiList(
      path: 'appro/imports',
      builder: (c, all, reload) {
        final base = all.where((f) {
          if (_search.isNotEmpty && !'${f['number']} ${f['title']} ${f['partner'] ?? ''}'.toLowerCase().contains(_search)) return false;
          final sup = _f['supplier'], co = _f['country'], tp = _f['transport'], dv = _f['currency'], lt = _f['late'];
          if (sup != null && '${f['partner'] ?? ''}' != sup) return false;
          if (co != null && '${f['origin_country'] ?? ''}' != co) return false;
          if (tp != null && '${f['transport'] ?? 'sea'}' != tp) return false;
          if (dv != null && '${f['currency'] ?? ''}' != dv) return false;
          if (lt == 'late' && f['late'] != true) return false;
          if (_period != null && !_period!.contains(f['eta'])) return false;
          return true;
        }).toList();
        final statuses = _statusOptions(all);
        final rows = base.where((f) => _status.isEmpty || '${f['status']}' == _status).toList();
        return ListView(padding: const EdgeInsets.all(16), children: [
        FilterBar(
          padding: const EdgeInsets.only(bottom: 12),
          search: _search,
          searchHint: 'N°, objet, fournisseur…',
          onSearch: (v) => setState(() => _search = v.toLowerCase()),
          statuses: statuses,
          status: _status,
          onStatus: (v) => setState(() => _status = v),
          statusCounts: _statusCounts(statuses, base),
          filters: [
            FilterSpec('supplier', 'Fournisseur', filterOptions(all, 'partner'), icon: Icons.local_shipping_outlined),
            FilterSpec('country', 'Origine', filterOptions(all, 'origin_country'), icon: Icons.public),
            const FilterSpec('transport', 'Transport', {'sea': 'Maritime', 'air': 'Aérien', 'road': 'Routier'}, icon: Icons.directions_boat_outlined),
            FilterSpec('currency', 'Devise', filterOptions(all, 'currency'), icon: Icons.currency_exchange),
            const FilterSpec('late', 'Arrivée', {'late': 'En retard'}, icon: Icons.schedule),
          ],
          values: _f,
          onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
          showPeriod: true,
          period: _period,
          onPeriod: (v) => setState(() => _period = v),
          count: rows.length,
          countLabel: 'dossier',
          onReset: () => setState(() => (_search = '', _status = '', _f = {}, _period = null)),
          trailing: [
            if (manage) FilledButton.icon(onPressed: () => _openImport(context, meta, null).then((_) => reload()), icon: const Icon(Icons.add), label: Text(tr('Nouveau dossier d\'importation'))),
          ],
        ),
        if (rows.isEmpty && all.isNotEmpty) EmptyState(icon: Icons.search_off, title: tr('Aucun résultat'))
        else if (rows.isEmpty)
          EmptyState(icon: Icons.directions_boat_outlined, title: tr('Aucune importation'), message: tr('Saisissez les articles en devise et les frais d\'approche (fret, assurance, douane, transit) : Yomara calcule le coût de revient réel de chaque article.')),
        for (final f in rows)
          Card(
            child: InkWell(
              borderRadius: BorderRadius.circular(16),
              onTap: () => _openImport(context, meta, f['id'] as int).then((_) => reload()),
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: Row(children: [
                  CircleAvatar(backgroundColor: _importColor('${f['status']}').withValues(alpha: .12), child: Icon(f['transport'] == 'air' ? Icons.flight : f['transport'] == 'road' ? Icons.local_shipping_outlined : Icons.directions_boat_outlined, color: _importColor('${f['status']}'))),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(tr('${f['number']} · ${f['title']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                      Text(
                        tr([
                          if (f['partner'] != null) '${f['partner']}',
                          if (f['origin_country'] != null) '${f['origin_country']}',
                          '${f['currency']}${f['currency'] != cur ? ' à ${_q(f['exchange_rate'])}' : ''}',
                          if (f['eta'] != null) 'arrivée ${dateFr(f['eta'])}',
                          '${f['lines_count']} article(s)',
                        ].join(' · ')),
                        style: TextStyle(color: f['late'] == true ? YColors.danger : hint, fontSize: 12.5),
                      ),
                    ]),
                  ),
                  Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
                    Text(tr(money(toDouble(f['totals']['landed']), cur)), style: const TextStyle(fontWeight: FontWeight.w800)),
                    Text(tr(f['totals']['coefficient'] == null ? '' : 'coef. ${NumberFormat('0.000', 'fr').format(toDouble(f['totals']['coefficient']))}'), style: TextStyle(color: hint, fontSize: 12)),
                    const SizedBox(height: 4),
                    StatusChip('${f['status_label']}', color: _importColor('${f['status']}')),
                  ]),
                ]),
              ),
            ),
          ),
        ]);
      },
    );
  }
}

Future<void> _openImport(BuildContext context, Map<String, dynamic> meta, int? id) =>
    Navigator.of(context).push(MaterialPageRoute(builder: (_) => _ImportPage(meta: meta, id: id)));

class _ImportPage extends StatefulWidget {
  const _ImportPage({required this.meta, this.id});

  final Map<String, dynamic> meta;
  final int? id;

  @override
  State<_ImportPage> createState() => _ImportPageState();
}

class _ImportPageState extends State<_ImportPage> {
  Map<String, dynamic>? _f;
  bool _busy = false;
  late int? _id = widget.id;
  final _title = TextEditingController();
  final _origin = TextEditingController();
  final _rate = TextEditingController();
  final _forwarder = TextEditingController();
  final _tracking = TextEditingController();
  final _vat = TextEditingController(text: '18');
  String? _partner, _incoterm = 'FOB', _eta, _order;
  late String _currency = '${widget.meta['currency']}';
  String _transport = 'sea', _allocation = 'value', _status = 'draft';
  final List<Map<String, dynamic>> _lines = [];
  final List<Map<String, dynamic>> _costs = [];

  @override
  void initState() {
    super.initState();
    if (_id != null) {
      _load();
    } else {
      _lines.add(_newLine());
      for (final t in ['freight', 'insurance', 'transit', 'port', 'local_transport']) {
        _costs.add(_newCost(t));
      }
    }
  }

  Map<String, dynamic> _newLine([Map? l]) => {
        'product_id': l?['product_id'], 'description': TextEditingController(text: '${l?['description'] ?? ''}'), 'hs_code': TextEditingController(text: '${l?['hs_code'] ?? ''}'),
        'quantity': TextEditingController(text: l == null ? '1' : _q(l['quantity'])), 'unit_price': TextEditingController(text: l == null ? '' : _q(l['unit_price'])),
        'weight': TextEditingController(text: l == null || toDouble(l['weight']) == 0 ? '' : _q(l['weight'])), 'duty_rate': TextEditingController(text: l == null ? '' : _q(l['duty_rate'])),
      };

  Map<String, dynamic> _newCost(String type, [Map? x]) =>
      {'type': type, 'label': TextEditingController(text: '${x?['label'] ?? ''}'), 'amount': TextEditingController(text: x == null ? '' : '${toDouble(x['amount']).round()}'), 'allocation': x?['allocation']};

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('appro/imports/$_id');
      _fill(asMap(r['data']));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  void _fill(Map<String, dynamic> f) {
    setState(() {
      _f = f;
      _id = f['id'] as int;
      _title.text = '${f['title']}';
      _origin.text = '${f['origin_country'] ?? ''}';
      _rate.text = _q(f['exchange_rate']);
      _forwarder.text = '${f['forwarder'] ?? ''}';
      _tracking.text = '${f['tracking'] ?? ''}';
      _vat.text = _q(f['vat_rate']);
      _partner = f['partner_id'] as String?;
      _incoterm = f['incoterm'] as String?;
      _eta = f['eta'] as String?;
      _order = f['order_date'] as String?;
      _currency = '${f['currency']}';
      _transport = '${f['transport']}';
      _allocation = '${f['allocation']}';
      _status = '${f['status']}';
      _lines
        ..clear()
        ..addAll([for (final l in f['lines'] as List) _newLine(l as Map)]);
      _costs
        ..clear()
        ..addAll([for (final x in f['cost_items'] as List) _newCost('${(x as Map)['type']}', x)]);
    });
  }

  Future<void> _save() async {
    setState(() => _busy = true);
    final body = {
      'title': _title.text.trim(), 'partner_id': _partner, 'origin_country': _origin.text.trim(), 'currency': _currency, 'exchange_rate': toDouble(_rate.text), 'incoterm': _incoterm, 'transport': _transport,
      'allocation': _allocation, 'vat_rate': toDouble(_vat.text), 'eta': _eta, 'order_date': _order, 'forwarder': _forwarder.text.trim(), 'tracking': _tracking.text.trim(), 'status': _status,
      'lines': [
        for (final l in _lines)
          {'product_id': l['product_id'], 'description': (l['description'] as TextEditingController).text, 'hs_code': (l['hs_code'] as TextEditingController).text, 'quantity': toDouble((l['quantity'] as TextEditingController).text),
            'unit_price': toDouble((l['unit_price'] as TextEditingController).text), 'weight': toDouble((l['weight'] as TextEditingController).text), 'duty_rate': toDouble((l['duty_rate'] as TextEditingController).text)},
      ],
      'costs': [
        for (final x in _costs) {'type': x['type'], 'label': (x['label'] as TextEditingController).text, 'amount': toDouble((x['amount'] as TextEditingController).text), 'allocation': x['allocation']},
      ],
    };
    try {
      final api = context.read<Session>().api;
      final r = _id == null ? await api.post('appro/imports', body) : await api.put('appro/imports/$_id', body);
      _fill(asMap(r['data']));
      if (mounted) toast(context, tr('Dossier enregistré : coût de revient recalculé.'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _action(String a, String done) async {
    await _save();
    if (_id == null || !mounted) return;
    setState(() => _busy = true);
    try {
      final r = await context.read<Session>().api.post('appro/imports/$_id/$a');
      _fill(asMap(r['data']));
      if (mounted) toast(context, a == 'apply' ? '${r['updated']} article(s) mis à jour.' : done);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final m = widget.meta;
    final cur = context.watch<Session>().currency;
    final hint = Theme.of(context).hintColor;
    final products = _products();
    final f = _f;
    final costTypes = asMap(m['cost_types']);
    final allocations = asMap(m['allocations']);
    final currencies = [for (final x in m['currencies'] as List) '${(x as Map)['code']}'];
    if (!currencies.contains(_currency)) currencies.add(_currency);
    final t = f == null ? null : asMap(f['totals']);
    final calc = f == null ? const <Map>[] : List<Map>.from(f['lines'] as List);
    return Scaffold(
      appBar: AppBar(title: Text(tr(f == null ? 'Nouvelle importation' : '${f['number']} · ${f['title']}')), actions: [
        if (_id != null) IconButton(tooltip: tr('PDF'), onPressed: () => _openPdf(context, 'appro/imports/$_id/pdf', 'Coût de revient ${f?['number']}'), icon: const Icon(Icons.picture_as_pdf_outlined)),
        if (_id != null) IconButton(tooltip: tr('Excel'), onPressed: () => _saveXlsx(context, 'appro/imports/$_id/xlsx', 'cout-de-revient-${f?['number']}'), icon: const Icon(Icons.grid_on_outlined)),
        Padding(padding: const EdgeInsets.only(right: 12), child: FilledButton(onPressed: _busy ? null : _save, child: Text(tr('Enregistrer et calculer')))),
      ]),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        SectionCard(
          title: tr('Dossier'),
          child: Column(children: [
            Row(children: [
              Expanded(flex: 3, child: TextField(controller: _title, decoration: InputDecoration(labelText: tr('Nom du dossier (ex. Câbles et disjoncteurs — Chine)')))),
              const SizedBox(width: 12),
              Expanded(flex: 2, child: _supplierPicker(_partner, (v) => setState(() => _partner = v), name: _f?['partner'] as String?)),
              const SizedBox(width: 12),
              Expanded(child: TextField(controller: _origin, decoration: InputDecoration(labelText: tr('Pays d\'origine')))),
            ]),
            const SizedBox(height: 8),
            Row(children: [
              SizedBox(
                width: 110,
                child: DropdownButtonFormField<String>(
                  initialValue: _currency,
                  decoration: InputDecoration(labelText: tr('Devise')),
                  items: [for (final c in currencies) DropdownMenuItem(value: c, child: Text(tr(c)))],
                  onChanged: (v) => setState(() {
                    _currency = v ?? cur;
                    final r = (m['currencies'] as List).cast<Map>().where((x) => x['code'] == v).firstOrNull;
                    if (r != null && r['rate'] != null) _rate.text = _q(r['rate']);
                    if (v == cur) _rate.text = '1';
                  }),
                ),
              ),
              const SizedBox(width: 12),
              SizedBox(width: 190, child: TextField(controller: _rate, inputFormatters: _num, enabled: _currency != cur, decoration: InputDecoration(labelText: tr('Cours (1 $_currency en $cur)')))),
              const SizedBox(width: 12),
              SizedBox(
                width: 110,
                child: DropdownButtonFormField<String>(
                  initialValue: _incoterm,
                  decoration: InputDecoration(labelText: tr('Incoterm')),
                  items: [for (final i in m['incoterms'] as List) DropdownMenuItem(value: '$i', child: Text(tr('$i')))],
                  onChanged: (v) => setState(() => _incoterm = v),
                ),
              ),
              const SizedBox(width: 12),
              SizedBox(
                width: 190,
                child: DropdownButtonFormField<String>(
                  initialValue: _transport,
                  decoration: InputDecoration(labelText: tr('Transport')),
                  items: [for (final e in asMap(m['transports']).entries) DropdownMenuItem(value: e.key, child: Text(tr('${e.value}')))],
                  onChanged: (v) => setState(() => _transport = v ?? 'sea'),
                ),
              ),
              const SizedBox(width: 12),
              SizedBox(
                width: 180,
                child: DropdownButtonFormField<String>(
                  initialValue: _status,
                  decoration: InputDecoration(labelText: tr('Étape')),
                  items: [for (final e in asMap(m['import_statuses']).entries) DropdownMenuItem(value: e.key, child: Text(tr('${e.value}')))],
                  onChanged: (v) => setState(() => _status = v ?? 'draft'),
                ),
              ),
            ]),
            const SizedBox(height: 8),
            Align(alignment: Alignment.centerLeft, child: Wrap(spacing: 12, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
              _dateButton(context, 'Commande', _order, (v) => setState(() => _order = v)),
              _dateButton(context, 'Arrivée prévue', _eta, (v) => setState(() => _eta = v)),
              SizedBox(width: 260, child: TextField(controller: _forwarder, decoration: InputDecoration(labelText: tr('Transitaire')))),
              SizedBox(width: 200, child: TextField(controller: _tracking, decoration: InputDecoration(labelText: tr('N° BL / LTA / suivi')))),
              SizedBox(width: 120, child: TextField(controller: _vat, inputFormatters: _num, decoration: InputDecoration(labelText: tr('TVA import %')))),
            ])),
          ]),
        ),
        const SizedBox(height: 12),
        SectionCard(
          title: tr('Articles commandés (prix en $_currency)'),
          trailing: TextButton.icon(onPressed: () => setState(() => _lines.add(_newLine())), icon: const Icon(Icons.add, size: 18), label: Text(tr('Article'))),
          child: Column(children: [
            for (var i = 0; i < _lines.length; i++)
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: Row(children: [
                  Expanded(
                    flex: 4,
                    child: Autocomplete<Map<String, dynamic>>(
                      key: ValueKey(_lines[i]),
                      initialValue: TextEditingValue(text: (_lines[i]['description'] as TextEditingController).text),
                      optionsBuilder: (v) => v.text.length < 2 ? const [] : products.where((p) => '${p['name']} ${p['sku'] ?? ''}'.toLowerCase().contains(v.text.toLowerCase())).take(15),
                      displayStringForOption: (p) => '${p['name']}',
                      onSelected: (p) => setState(() => (_lines[i]['product_id'] = p['id'], (_lines[i]['description'] as TextEditingController).text = '${p['name']}')),
                      fieldViewBuilder: (c, ctl, focus, _) => TextField(
                        controller: ctl,
                        focusNode: focus,
                        onChanged: (v) => (_lines[i]['description'] as TextEditingController).text = v,
                        decoration: InputDecoration(isDense: true, labelText: tr('Article'), prefixIcon: _lines[i]['product_id'] != null ? const Icon(Icons.link, size: 16) : null),
                      ),
                    ),
                  ),
                  const SizedBox(width: 8),
                  SizedBox(width: 100, child: TextField(controller: _lines[i]['hs_code'], decoration: InputDecoration(isDense: true, labelText: tr('Code SH')))),
                  const SizedBox(width: 8),
                  SizedBox(width: 80, child: TextField(controller: _lines[i]['quantity'], inputFormatters: _num, textAlign: TextAlign.right, decoration: InputDecoration(isDense: true, labelText: tr('Qté')))),
                  const SizedBox(width: 8),
                  SizedBox(width: 100, child: TextField(controller: _lines[i]['unit_price'], inputFormatters: _num, textAlign: TextAlign.right, decoration: InputDecoration(isDense: true, labelText: tr('PU $_currency')))),
                  const SizedBox(width: 8),
                  SizedBox(width: 80, child: TextField(controller: _lines[i]['weight'], inputFormatters: _num, textAlign: TextAlign.right, decoration: InputDecoration(isDense: true, labelText: tr('kg/u')))),
                  const SizedBox(width: 8),
                  SizedBox(width: 100, child: TextField(controller: _lines[i]['duty_rate'], inputFormatters: _num, textAlign: TextAlign.right, decoration: InputDecoration(isDense: true, labelText: tr('Douane %')))),
                  IconButton(onPressed: _lines.length > 1 ? () => setState(() => _lines.removeAt(i)) : null, icon: const Icon(Icons.close, size: 18)),
                ]),
              ),
          ]),
        ),
        const SizedBox(height: 12),
        SectionCard(
          title: tr('Frais d\'approche (en $cur)'),
          trailing: Wrap(spacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
            SizedBox(
              width: 230,
              child: DropdownButtonFormField<String>(
                isDense: true,
                initialValue: _allocation,
                decoration: InputDecoration(isDense: true, labelText: tr('Répartition')),
                items: [for (final e in allocations.entries) DropdownMenuItem(value: e.key, child: Text(tr('${e.value}')))],
                onChanged: (v) => setState(() => _allocation = v ?? 'value'),
              ),
            ),
            PopupMenuButton<String>(
              tooltip: tr('Ajouter un frais'),
              onSelected: (v) => setState(() => _costs.add(_newCost(v))),
              itemBuilder: (_) => [for (final e in costTypes.entries) PopupMenuItem(value: e.key, child: Text(tr('${e.value}')))],
              child: const Padding(padding: EdgeInsets.all(8), child: Icon(Icons.add)),
            ),
          ]),
          child: Column(children: [
            for (var i = 0; i < _costs.length; i++)
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: Row(children: [
                  SizedBox(width: 260, child: Text(tr('${costTypes[_costs[i]['type']]}'), style: const TextStyle(fontWeight: FontWeight.w600))),
                  Expanded(child: TextField(controller: _costs[i]['label'], decoration: InputDecoration(isDense: true, hintText: tr('Précision (facultatif)')))),
                  const SizedBox(width: 8),
                  SizedBox(width: 140, child: TextField(controller: _costs[i]['amount'], inputFormatters: _num, textAlign: TextAlign.right, decoration: InputDecoration(isDense: true, labelText: tr('Montant $cur')))),
                  const SizedBox(width: 8),
                  SizedBox(
                    width: 170,
                    child: DropdownButtonFormField<String?>(
                      isDense: true,
                      initialValue: _costs[i]['allocation'] as String?,
                      decoration: const InputDecoration(isDense: true),
                      items: [DropdownMenuItem<String?>(value: null, child: Text(tr('Par défaut'))), for (final e in allocations.entries) DropdownMenuItem<String?>(value: e.key, child: Text(tr('${e.value}'.replaceAll('Au prorata ', '')), overflow: TextOverflow.ellipsis))],
                      onChanged: (v) => setState(() => _costs[i]['allocation'] = v),
                    ),
                  ),
                  IconButton(onPressed: () => setState(() => _costs.removeAt(i)), icon: const Icon(Icons.close, size: 18)),
                ]),
              ),
            Text(tr('Les droits de douane se calculent ligne par ligne (taux en %) sur la valeur CAF, ou se saisissent ici en montant global. La TVA à l\'import est récupérable : elle n\'entre pas dans le coût.'), style: TextStyle(fontSize: 12, color: hint)),
          ]),
        ),
        if (t != null) ...[
          const SizedBox(height: 12),
          SectionCard(
            title: tr('Coût de revient'),
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              KpiWrap(children: [
                _kpi(context, 'Valeur d\'achat convertie', money(toDouble(t['value']), cur), Icons.shopping_bag_outlined, YColors.blue, sub: '${_q(t['value_foreign'])} $_currency'),
                _kpi(context, 'Frais d\'approche', money(toDouble(t['costs']), cur), Icons.local_shipping_outlined, YColors.warning, sub: 'dont douane ${money(toDouble(t['duty']), cur)}'),
                _kpi(context, 'Coût de revient total', money(toDouble(t['landed']), cur), Icons.inventory_2_outlined, YColors.success, sub: 'TVA import ${money(toDouble(t['vat_import']), cur)} (récupérable)'),
                _kpi(context, 'Coefficient d\'approche', t['coefficient'] == null ? '—' : NumberFormat('0.000', 'fr').format(toDouble(t['coefficient'])), Icons.calculate_outlined, YColors.night),
              ]),
              const SizedBox(height: 10),
              SingleChildScrollView(
                scrollDirection: Axis.horizontal,
                child: DataTable(columnSpacing: 18, columns: [
                  DataColumn(label: Text(tr('Article'))), DataColumn(label: Text(tr('Qté')), numeric: true), DataColumn(label: Text(tr('Valeur')), numeric: true), DataColumn(label: Text(tr('Fret + assur.')), numeric: true),
                  DataColumn(label: Text(tr('Douane')), numeric: true), DataColumn(label: Text(tr('Autres frais')), numeric: true), DataColumn(label: Text(tr('Coût unitaire')), numeric: true),
                  DataColumn(label: Text(tr('Coût actuel')), numeric: true), DataColumn(label: Text(tr('Marge')), numeric: true),
                ], rows: [
                  for (final l in calc)
                    DataRow(cells: [
                      DataCell(ConstrainedBox(constraints: const BoxConstraints(maxWidth: 260), child: Text(tr('${l['description']}'), overflow: TextOverflow.ellipsis))),
                      DataCell(Text(tr(_q(l['quantity'])))),
                      DataCell(Text(tr(money(toDouble(l['value']), cur)))),
                      DataCell(Text(tr(money(toDouble(l['cif_costs']), cur)))),
                      DataCell(Text(tr(money(toDouble(l['duty']), cur)))),
                      DataCell(Text(tr(money(toDouble(l['other_costs']), cur)))),
                      DataCell(Text(tr(money(toDouble(l['unit_landed']), cur)), style: const TextStyle(fontWeight: FontWeight.w800))),
                      DataCell(Text(tr(l['current_cost'] == null ? '—' : money(toDouble(l['current_cost']), cur)), style: TextStyle(color: hint))),
                      DataCell(Text(tr(l['margin'] == null ? '—' : '${l['margin']} %'), style: TextStyle(color: l['margin'] == null ? hint : toDouble(l['margin']) < 15 ? YColors.danger : YColors.success, fontWeight: FontWeight.w700))),
                    ]),
                ]),
              ),
              const SizedBox(height: 10),
              Wrap(spacing: 8, runSpacing: 8, children: [
                FilledButton.tonalIcon(
                  onPressed: _busy ? null : () async {
                    if (await confirm(context, tr('Mettre à jour le coût d\'achat ?'), tr('Le coût unitaire de revient remplace le coût d\'achat des articles liés au catalogue (marges et valorisation du stock).'), ok: tr('Mettre à jour'))) {
                      await _action('apply', '');
                    }
                  },
                  icon: const Icon(Icons.price_change_outlined),
                  label: Text(tr(f!['applied_at'] == null ? 'Reporter le coût sur les articles' : 'Reporter à nouveau sur les articles')),
                ),
                if (f['purchase_order_id'] == null)
                  OutlinedButton.icon(onPressed: _busy ? null : () => _action('order', 'Bon de commande préparé dans les Achats.'), icon: const Icon(Icons.receipt_long_outlined), label: Text(tr('Créer le bon de commande en $_currency')))
                else
                  Chip(avatar: const Icon(Icons.receipt_long_outlined, size: 16), label: Text(tr('Bon de commande ${f['order_number'] ?? ''}'))),
              ]),
            ]),
          ),
        ],
      ]),
    );
  }
}

// ---------------------------------------------------------------------------------------------- comparatifs

class _ComparisonsTab extends StatefulWidget {
  const _ComparisonsTab({super.key, required this.meta, required this.manage, required this.onChanged});

  final Map<String, dynamic> meta;
  final bool manage;
  final VoidCallback onChanged;

  @override
  State<_ComparisonsTab> createState() => _ComparisonsTabState();
}

class _ComparisonsTabState extends State<_ComparisonsTab> {
  String _search = '';
  String _status = '';
  Map<String, String?> _f = {};
  FilterPeriod? _period;

  static const _labels = {'draft': 'Brouillon', 'open': 'Ouvert', 'awarded': 'Attribué', 'cancelled': 'Annulé'};

  @override
  Widget build(BuildContext context) {
    final manage = widget.manage;
    final cur = context.watch<Session>().currency;
    final hint = Theme.of(context).hintColor;
    return _ApiList(
      path: 'appro/comparisons',
      builder: (c, all, reload) {
        final base = all.where((x) {
          if (_search.isNotEmpty && !'${x['number']} ${x['title']} ${x['awarded_partner'] ?? ''} ${x['source_label'] ?? ''}'.toLowerCase().contains(_search)) return false;
          final aw = _f['awarded'], src = _f['source'], on = _f['online'];
          if (aw != null && '${x['awarded_partner'] ?? ''}' != aw) return false;
          if (src != null && '${x['source_label'] ?? ''}' != src) return false;
          if (on == 'unread' && !(x['online'] != null && toDouble(x['online']['unread']) > 0)) return false;
          if (on == 'invited' && !(x['online'] != null && toDouble(x['online']['invited']) > 0)) return false;
          if (_period != null && !_period!.contains(x['deadline'])) return false;
          return true;
        }).toList();
        final statuses = _statusOptions(all, _labels);
        final rows = base.where((x) => _status.isEmpty || '${x['status']}' == _status).toList();
        return ListView(padding: const EdgeInsets.all(16), children: [
        FilterBar(
          padding: const EdgeInsets.only(bottom: 12),
          search: _search,
          searchHint: 'N°, objet, fournisseur retenu…',
          onSearch: (v) => setState(() => _search = v.toLowerCase()),
          statuses: statuses.length > 2 ? statuses : null,
          status: _status,
          onStatus: (v) => setState(() => _status = v),
          statusCounts: _statusCounts(statuses, base),
          filters: [
            FilterSpec('awarded', 'Fournisseur retenu', filterOptions(all, 'awarded_partner'), icon: Icons.emoji_events_outlined),
            FilterSpec('source', 'Origine', filterOptions(all, 'source_label'), icon: Icons.link),
            const FilterSpec('online', 'Consultation en ligne', {'invited': 'Fournisseurs invités', 'unread': 'Messages non lus'}, icon: Icons.forum_outlined),
          ],
          values: _f,
          onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
          showPeriod: true,
          period: _period,
          onPeriod: (v) => setState(() => _period = v),
          count: rows.length,
          countLabel: 'comparatif',
          onReset: () => setState(() => (_search = '', _status = '', _f = {}, _period = null)),
          trailing: [
            if (manage)
            FilledButton.icon(
              onPressed: () async {
                try {
                  final r = await context.read<Session>().api.post('appro/comparisons', {'title': 'Nouveau comparatif', 'lines': [{'description': 'Article', 'quantity': 1}]});
                  if (context.mounted) await _openComparison(context, r['data']['id'] as int);
                  await reload();
                } catch (e) {
                  if (context.mounted) toast(context, errorText(e), error: true);
                }
              },
              icon: const Icon(Icons.add),
              label: Text(tr('Nouveau comparatif')),
            ),
          ],
        ),
        if (rows.isEmpty && all.isNotEmpty) EmptyState(icon: Icons.search_off, title: tr('Aucun résultat'))
        else if (rows.isEmpty) EmptyState(icon: Icons.compare_arrows, title: tr('Aucun comparatif'), message: tr('Comparez les offres de plusieurs fournisseurs ligne par ligne, en devises différentes, puis attribuez : le bon de commande est prêt.')),
        for (final x in rows)
          Card(
            child: ListTile(
              onTap: () => _openComparison(context, x['id'] as int).then((_) => reload()),
              leading: CircleAvatar(backgroundColor: (x['status'] == 'awarded' ? YColors.success : x['status'] == 'cancelled' ? YColors.muted : const Color(0xFF8E44AD)).withValues(alpha: .12),
                  child: Icon(Icons.compare_arrows, color: x['status'] == 'awarded' ? YColors.success : const Color(0xFF8E44AD))),
              title: Text(tr('${x['number']} · ${x['title']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
              subtitle: Text(
                tr([
                  '${x['lines_count']} ligne(s) · ${x['offers_count']} offre(s)',
                  if (x['online'] != null && toDouble(x['online']['invited']) > 0) '${x['online']['answered']}/${x['online']['invited']} réponse(s) en ligne',
                  if (x['online'] != null && toDouble(x['online']['unread']) > 0) '${x['online']['unread']} message(s) non lu(s)',
                  if (x['source_label'] != null) '${x['source_label']}',
                  if (x['deadline'] != null) 'réponses avant le ${dateFr(x['deadline'])}',
                  if (x['awarded_partner'] != null) 'retenu : ${x['awarded_partner']}',
                ].join(' · ')),
                style: TextStyle(color: hint),
              ),
              trailing: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.end, children: [
                Text(tr(money(toDouble(x['awarded_total'] ?? x['best_total']), cur)), style: const TextStyle(fontWeight: FontWeight.w800)),
                if (toDouble(x['spread']) > 0) Text(tr('écart ${money(toDouble(x['spread']), cur)}'), style: TextStyle(color: hint, fontSize: 12)),
              ]),
            ),
          ),
        ]);
      },
    );
  }
}

Future<void> _openComparison(BuildContext context, int id) => Navigator.of(context).push(MaterialPageRoute(builder: (_) => _ComparisonPage(id: id)));

class _ComparisonPage extends StatefulWidget {
  const _ComparisonPage({required this.id});

  final int id;

  @override
  State<_ComparisonPage> createState() => _ComparisonPageState();
}

class _ComparisonPageState extends State<_ComparisonPage> {
  Map<String, dynamic>? _c;
  bool _busy = false;
  final _title = TextEditingController();
  final _instructions = TextEditingController();
  String? _deadline;
  List<Map<String, dynamic>> _lines = [];
  List<Map<String, dynamic>> _offers = [];
  late final bool _manage = context.read<Session>().can('appro.manage');

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('appro/comparisons/${widget.id}');
      _fill(asMap(r['data']));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  void _fill(Map<String, dynamic> c) {
    setState(() {
      _c = c;
      _title.text = '${c['title']}';
      _instructions.text = '${c['instructions'] ?? ''}';
      _deadline = c['deadline'] as String?;
      _lines = [
        for (final l in c['lines'] as List)
          {'key': (l as Map)['key'], 'product_id': l['product_id'], 'description': TextEditingController(text: '${l['description']}'), 'quantity': TextEditingController(text: _q(l['quantity'])), 'unit': l['unit']},
      ];
      _offers = [
        for (final o in c['offers'] as List)
          {
            'key': (o as Map)['key'], 'partner_id': o['partner_id'], 'partner_name': o['partner_name'], 'currency': o['currency'], 'rate': TextEditingController(text: _q(o['rate'])),
            'delay': TextEditingController(text: o['delay_days'] == null ? '' : '${o['delay_days']}'), 'extra': TextEditingController(text: toDouble(o['extra']) > 0 ? '${toDouble(o['extra']).round()}' : ''),
            'terms': TextEditingController(text: '${o['payment_terms'] ?? ''}'), 'compliant': o['compliant'] != false,
            'validity': o['validity'], 'notes': o['notes'], 'source': o['source'], 'submitted_at': o['submitted_at'], 'line_notes': o['line_notes'] is Map ? o['line_notes'] : const {},
            'prices': {for (final l in c['lines'] as List) '${(l as Map)['key']}': TextEditingController(text: o['prices'] is Map && (o['prices'] as Map)[l['key']] != null ? _q((o['prices'] as Map)[l['key']]) : '')},
          },
      ];
    });
  }

  Map<String, dynamic> _body() => {
        'title': _title.text.trim(),
        'deadline': _deadline,
        'instructions': _instructions.text.trim(),
        'lines': [for (final l in _lines) {'key': l['key'], 'product_id': l['product_id'], 'description': (l['description'] as TextEditingController).text, 'quantity': toDouble((l['quantity'] as TextEditingController).text), 'unit': l['unit']}],
        'offers': [
          for (final o in _offers)
            {
              'key': o['key'], 'partner_id': o['partner_id'], 'partner_name': o['partner_name'], 'currency': o['currency'], 'rate': toDouble((o['rate'] as TextEditingController).text),
              'delay_days': (o['delay'] as TextEditingController).text.isEmpty ? null : int.tryParse((o['delay'] as TextEditingController).text), 'extra': toDouble((o['extra'] as TextEditingController).text),
              'payment_terms': (o['terms'] as TextEditingController).text, 'compliant': o['compliant'], 'validity': o['validity'], 'notes': o['notes'],
              'prices': {for (final e in (o['prices'] as Map<String, TextEditingController>).entries) if (e.value.text.trim().isNotEmpty) e.key: toDouble(e.value.text)},
            },
        ],
      };

  Future<void> _save() async {
    setState(() => _busy = true);
    try {
      final r = await context.read<Session>().api.put('appro/comparisons/${widget.id}', _body());
      _fill(asMap(r['data']));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _post(String action, [Map<String, dynamic> body = const {}, String? done]) async {
    if (_c?['status'] == 'open') await _save();
    if (!mounted) return;
    setState(() => _busy = true);
    try {
      final r = await context.read<Session>().api.post('appro/comparisons/${widget.id}/$action', body);
      _fill(asMap(r['data']));
      if (done != null && mounted) toast(context, done);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  /// Invite des fournisseurs à répondre en ligne (lien personnel par e-mail / WhatsApp, ou à partager).
  Future<void> _consult() async {
    if (_c?['status'] == 'open') await _save();
    if (!mounted) return;
    final already = {for (final i in (_c?['invites'] as List? ?? const [])) '${(i as Map)['partner_id']}'};
    final list = _suppliers();
    final picked = <String>{for (final o in _offers) if (o['partner_id'] != null && !already.contains(o['partner_id'])) '${o['partner_id']}'};
    final msg = TextEditingController();
    var email = true, whatsapp = true;
    final ok = await showDialog<bool>(
      context: context,
      builder: (dc) => StatefulBuilder(
        builder: (dc, set) => YAlertDialog(
          title: Text(tr('Consulter des fournisseurs')),
          content: SizedBox(
            width: 520,
            height: 500,
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(tr('Chaque fournisseur reçoit un lien personnel : il saisit ses prix, son délai et ses conditions, joint son devis et peut poser ses questions. Son offre arrive directement dans le comparatif.'),
                  style: TextStyle(color: Theme.of(dc).hintColor, fontSize: 13)),
              const SizedBox(height: 8),
              Expanded(
                child: ListView(children: [
                  for (final p in list)
                    CheckboxListTile(
                      dense: true,
                      value: picked.contains(p['id']),
                      title: Text(tr('${p['name']}${already.contains(p['id']) ? ' (déjà consulté)' : ''}')),
                      subtitle: Text(tr([if (p['email'] != null) '${p['email']}', if (p['phone'] != null) '${p['phone']}'].join(' · ').ifEmpty('ni e-mail ni téléphone : lien à transmettre'))),
                      onChanged: (v) => set(() => v == true ? picked.add('${p['id']}') : picked.remove(p['id'])),
                    ),
                ]),
              ),
              TextField(controller: msg, maxLines: 3, decoration: InputDecoration(labelText: tr('Message (facultatif)'))),
              Row(children: [
                Checkbox(value: email, onChanged: (v) => set(() => email = v ?? true)),
                Text(tr('E-mail')),
                const SizedBox(width: 12),
                Checkbox(value: whatsapp, onChanged: (v) => set(() => whatsapp = v ?? true)),
                Text(tr('WhatsApp (si configuré)')),
              ]),
            ]),
          ),
          actions: [TextButton(onPressed: () => Navigator.pop(dc, false), child: Text(tr('Annuler'))), FilledButton(onPressed: picked.isEmpty ? null : () => Navigator.pop(dc, true), child: Text(tr('Envoyer le lien (${picked.length})')))],
        ),
      ),
    );
    if (ok != true || !mounted) return;
    setState(() => _busy = true);
    try {
      final r = await context.read<Session>().api.post('appro/comparisons/${widget.id}/invite', {
        'partner_ids': picked.toList(),
        'message': msg.text.trim().isEmpty ? null : msg.text.trim(),
        'channels': [if (email) 'email', if (whatsapp) 'whatsapp'],
      });
      _fill(asMap(r['data']));
      final res = asMap(r['result']);
      final share = List<Map>.from(res['to_share'] as List);
      if (!mounted) return;
      if (share.isEmpty) {
        toast(context, tr('${res['sent']} fournisseur(s) consulté(s) : le lien leur a été envoyé.'));
      } else {
        await _shareLinks(share, sent: res['sent'] as int);
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  /// Liens à transmettre soi-même (pas d'e-mail, pas de WhatsApp configuré).
  Future<void> _shareLinks(List<Map> rows, {int sent = 0}) => showDialog(
        context: context,
        builder: (dc) => YAlertDialog(
          title: Text(tr(sent > 0 ? '$sent envoi(s) fait(s), ${rows.length} lien(s) à transmettre' : 'Liens à transmettre')),
          content: SizedBox(
            width: 560,
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              for (final x in rows)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  title: Text(tr('${x['name']}')),
                  subtitle: Text(tr('${x['url']}'), style: const TextStyle(fontSize: 12)),
                  trailing: Wrap(children: [
                    IconButton(
                      tooltip: tr('Copier le lien'),
                      onPressed: () {
                        Clipboard.setData(ClipboardData(text: '${x['url']}'));
                        toast(dc, tr('Lien copié.'));
                      },
                      icon: const Icon(Icons.copy, size: 18),
                    ),
                    IconButton(
                      tooltip: tr('Envoyer par WhatsApp'),
                      onPressed: () {
                        final phone = '${x['phone'] ?? ''}'.replaceAll(RegExp(r'[^0-9]'), '');
                        final text = Uri.encodeComponent('Bonjour, voici notre demande de prix « ${_c?['title']} ». Merci de saisir votre offre ici : ${x['url']}');
                        safeLaunchUrl(Uri.parse('https://wa.me/$phone?text=$text'), mode: LaunchMode.externalApplication);
                      },
                      icon: const Icon(Icons.chat_outlined, size: 18),
                    ),
                  ]),
                ),
            ]),
          ),
          actions: [FilledButton(onPressed: () => Navigator.pop(dc), child: Text(tr('Fermer')))],
        ),
      );

  /// Échanges avec un fournisseur : questions de l'acheteur, messages du fournisseur, réponses reçues par téléphone.
  Future<void> _thread(Map inv) async {
    final api = context.read<Session>().api;
    if (toDouble(inv['unread']) > 0) {
      try {
        final r = await api.post('appro/comparisons/${widget.id}/read');
        _fill(asMap(r['data']));
      } catch (_) {}
    }
    if (!mounted) return;
    final body = TextEditingController();
    var mode = 'out';
    await showDialog(
      context: context,
      builder: (dc) => StatefulBuilder(builder: (dc, set) {
        final hint = Theme.of(dc).hintColor;
        final msgs = [for (final m in (_c?['messages'] as List? ?? const [])) if ((m as Map)['invite_id'] == inv['id']) m];
        return YAlertDialog(
          title: Text(tr('Échanges avec ${inv['name']}')),
          content: SizedBox(
            width: 560,
            child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              ConstrainedBox(
                constraints: const BoxConstraints(maxHeight: 320),
                child: msgs.isEmpty
                    ? Padding(padding: const EdgeInsets.all(8), child: Text(tr('Aucun échange pour le moment.'), style: TextStyle(color: hint)))
                    : ListView(shrinkWrap: true, children: [
                        for (final m in msgs)
                          Align(
                            alignment: m['direction'] == 'in' ? Alignment.centerLeft : Alignment.centerRight,
                            child: Container(
                              margin: const EdgeInsets.symmetric(vertical: 4),
                              padding: const EdgeInsets.all(10),
                              constraints: const BoxConstraints(maxWidth: 420),
                              decoration: BoxDecoration(color: (m['direction'] == 'in' ? YColors.blue : YColors.warning).withValues(alpha: .1), borderRadius: BorderRadius.circular(12)),
                              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                                Text(
                                  tr('${m['author'] ?? ''} · ${DateFormat('dd/MM HH:mm').format(DateTime.parse('${m['at']}').toLocal())}'
                                  '${m['channel'] == 'phone' ? ' · par téléphone' : m['direction'] == 'out' && m['channel'] != 'portal' ? ' · envoyé par ${'${m['channel']}'.replaceAll('+', ' et ')}' : ''}'),
                                  style: TextStyle(fontSize: 11.5, color: hint),
                                ),
                                Text(tr('${m['body']}')),
                              ]),
                            ),
                          ),
                      ]),
              ),
              const Divider(),
              if (_manage) ...[
                SegmentedButton<String>(
                  segments: [ButtonSegment(value: 'out', label: Text(tr('Poser une question'))), ButtonSegment(value: 'in', label: Text(tr('Noter sa réponse (téléphone)')))],
                  selected: {mode},
                  onSelectionChanged: (v) => set(() => mode = v.first),
                ),
                const SizedBox(height: 8),
                TextField(controller: body, maxLines: 3, decoration: InputDecoration(labelText: trn(mode == 'out' ? 'Votre question (envoyée avec son lien)' : 'Ce que le fournisseur a répondu'))),
              ],
            ]),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(dc), child: Text(tr('Fermer'))),
            if (_manage)
              FilledButton(
                onPressed: () async {
                  if (body.text.trim().isEmpty) return;
                  try {
                    final r = await api.post('appro/comparisons/${widget.id}/messages', {'invite_id': inv['id'], 'body': body.text.trim(), 'direction': mode, 'channel': 'phone'});
                    _fill(asMap(r['data']));
                    body.clear();
                    set(() {});
                  } catch (e) {
                    if (dc.mounted) toast(dc, errorText(e), error: true);
                  }
                },
                child: Text(tr(mode == 'out' ? 'Envoyer' : 'Enregistrer')),
              ),
          ],
        );
      }),
    );
  }

  Widget _consultation(Map<String, dynamic> c) {
    final hint = Theme.of(context).hintColor;
    final invites = List<Map>.from(c['invites'] as List? ?? const []);
    final statusColor = {'sent': YColors.muted, 'opened': YColors.blue, 'answered': YColors.success, 'declined': YColors.danger};
    String when(dynamic v) => v == null ? '' : DateFormat('dd/MM HH:mm').format(DateTime.parse('$v').toLocal());
    return SectionCard(
      title: tr('Fournisseurs consultés en ligne'),
      trailing: _manage && c['status'] == 'open'
          ? FilledButton.tonalIcon(onPressed: _busy ? null : _consult, icon: const Icon(Icons.send_outlined, size: 18), label: Text(tr('Consulter des fournisseurs')))
          : null,
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        if (invites.isEmpty)
          Text(tr('Envoyez un lien aux fournisseurs : ils saisissent eux-mêmes leur offre, qui s\'ajoute au comparatif. Ils peuvent aussi poser leurs questions.'), style: TextStyle(color: hint)),
        for (final i in invites)
          ListTile(
            contentPadding: EdgeInsets.zero,
            leading: CircleAvatar(
              backgroundColor: (statusColor[i['status']] ?? YColors.muted).withValues(alpha: .12),
              child: Icon(i['status'] == 'answered' ? Icons.mark_email_read_outlined : i['status'] == 'declined' ? Icons.block : i['status'] == 'opened' ? Icons.visibility_outlined : Icons.outgoing_mail, color: statusColor[i['status']], size: 20),
            ),
            title: Row(children: [
              Flexible(child: Text(tr('${i['name']}'), overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w700))),
              const SizedBox(width: 8),
              StatusChip('${i['status_label']}', color: statusColor[i['status']] ?? YColors.muted),
              if (toDouble(i['unread']) > 0) ...[const SizedBox(width: 6), Badge(label: Text(tr('${i['unread']}')), child: const Icon(Icons.forum_outlined, size: 18))],
            ]),
            subtitle: Text(
              tr([
                'invité le ${when(i['sent_at'])}',
                if (i['opened_at'] != null) 'consulté le ${when(i['opened_at'])}',
                if (i['answered_at'] != null) 'offre le ${when(i['answered_at'])}${toDouble(i['revisions']) > 0 ? ' (modifiée ${i['revisions']} fois)' : ''}',
                if (i['reminded_at'] != null) 'relancé',
                if (i['decline_reason'] != null) 'motif : ${i['decline_reason']}',
              ].join(' · ')),
              style: TextStyle(color: hint, fontSize: 12.5),
            ),
            trailing: Wrap(children: [
              if (i['has_file'] == true)
                IconButton(
                  tooltip: tr('Devis joint : ${i['file_name']}'),
                  onPressed: () async {
                    try {
                      final bytes = await context.read<Session>().api.download('appro/comparisons/${widget.id}/invites/${i['id']}/file');
                      final where = await saveFile('${i['file_name'] ?? 'devis'}', bytes);
                      if (mounted) toast(context, tr('Fichier enregistré${where.isEmpty ? '' : ' : $where'}'));
                    } catch (e) {
                      if (mounted) toast(context, errorText(e), error: true);
                    }
                  },
                  icon: const Icon(Icons.attach_file, size: 20),
                ),
              IconButton(tooltip: tr('Questions et réponses'), onPressed: () => _thread(i), icon: const Icon(Icons.forum_outlined, size: 20)),
              IconButton(tooltip: tr('Copier / partager le lien'), onPressed: () => _shareLinks([i]), icon: const Icon(Icons.link, size: 20)),
            ]),
          ),
      ]),
    );
  }

  @override
  Widget build(BuildContext context) {
    final c = _c;
    final cur = context.watch<Session>().currency;
    final hint = Theme.of(context).hintColor;
    if (c == null) return const Scaffold(body: Center(child: CircularProgressIndicator()));
    final open = c['status'] == 'open' && _manage;
    final a = asMap(c['analysis']);
    final totals = {for (final o in a['offers'] as List) '${(o as Map)['key']}': o};
    final best = a['best_lines'] is Map ? asMap(a['best_lines']) : <String, dynamic>{};
    final suppliers = _suppliers();
    const colW = 185.0;
    Widget priceCell(Map o, Map l) {
      final isBest = best['${l['key']}']?['offer'] == o['key'];
      return SizedBox(
        width: colW,
        child: Padding(
          padding: const EdgeInsets.all(4),
          child: TextField(
            controller: (o['prices'] as Map<String, TextEditingController>)['${l['key']}'],
            enabled: open,
            inputFormatters: _num,
            textAlign: TextAlign.right,
            decoration: InputDecoration(
              isDense: true,
              filled: isBest,
              fillColor: YColors.success.withValues(alpha: .12),
              hintText: '—',
              prefixIcon: (o['line_notes'] as Map?)?['${l['key']}'] == null
                  ? null
                  : Tooltip(message: '${(o['line_notes'] as Map)['${l['key']}']}', child: const Icon(Icons.sticky_note_2_outlined, size: 16, color: YColors.blue)),
              prefixIconConstraints: const BoxConstraints(minWidth: 26),
            ),
          ),
        ),
      );
    }

    return Scaffold(
      appBar: AppBar(title: Text(tr('${c['number']} · ${c['title']}')), actions: [
        IconButton(tooltip: tr('PDF'), onPressed: () => _openPdf(context, 'appro/comparisons/${widget.id}/pdf', 'Comparatif ${c['number']}'), icon: const Icon(Icons.picture_as_pdf_outlined)),
        if (open) Padding(padding: const EdgeInsets.only(right: 12), child: FilledButton(onPressed: _busy ? null : _save, child: Text(tr('Enregistrer et comparer')))),
      ]),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        if (!open)
          Card(
            color: YColors.success.withValues(alpha: .08),
            child: ListTile(
              leading: const Icon(Icons.emoji_events_outlined, color: YColors.success),
              title: Text(tr(c['status'] == 'awarded' ? 'Attribué à ${c['awarded_partner']} : ${money(toDouble(c['awarded_total']), cur)}' : 'Comparatif annulé')),
              subtitle: Text(tr([if (c['award_note'] != null) '${c['award_note']}', if (c['order_number'] != null) 'Bon de commande ${c['order_number']}'].join(' · '))),
            ),
          ),
        if (c['source'] != null)
          Padding(
            padding: const EdgeInsets.only(bottom: 10),
            child: Row(children: [
              const Icon(Icons.link, size: 18, color: YColors.blue),
              const SizedBox(width: 6),
              Expanded(child: Text(tr('${c['source']['type_label']} : ${c['source']['label'] ?? ''}'), style: TextStyle(color: hint))),
            ]),
          ),
        if (open) ...[
          TextField(controller: _title, decoration: InputDecoration(labelText: tr('Objet du comparatif'))),
          const SizedBox(height: 8),
          Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Padding(padding: const EdgeInsets.only(top: 6), child: _dateButton(context, 'Date limite des offres', _deadline, (v) => setState(() => _deadline = v))),
            const SizedBox(width: 12),
            Expanded(child: TextField(controller: _instructions, maxLines: 2, minLines: 1, decoration: InputDecoration(labelText: tr('Consignes aux fournisseurs (livraison, garantie, pièces à joindre…)')))),
          ]),
        ] else if (c['deadline'] != null)
          Text(tr('Date limite des offres : ${dateFr(c['deadline'])}'), style: TextStyle(color: hint)),
        const SizedBox(height: 12),
        Wrap(spacing: 8, runSpacing: 8, children: [
          if (open)
            OutlinedButton.icon(
              onPressed: _busy
                  ? null
                  : () => setState(() => _offers.add({
                        'key': null, 'partner_id': null, 'partner_name': null, 'currency': cur, 'rate': TextEditingController(text: '1'), 'delay': TextEditingController(), 'extra': TextEditingController(),
                        'terms': TextEditingController(), 'compliant': true, 'prices': {for (final l in _lines) '${l['key']}': TextEditingController()},
                      })),
              icon: const Icon(Icons.person_add_alt),
              label: Text(tr('Ajouter une offre')),
            ),
          if (open) OutlinedButton.icon(onPressed: _busy ? null : () => _post('prefill', const {}, 'Tarifs connus ajoutés.'), icon: const Icon(Icons.auto_fix_high), label: Text(tr('Préremplir avec les tarifs connus'))),
          if (open)
            OutlinedButton.icon(
              onPressed: _busy
                  ? null
                  : () {
                      setState(() => _lines.add({'key': null, 'product_id': null, 'description': TextEditingController(text: tr('Nouvelle ligne')), 'quantity': TextEditingController(text: '1'), 'unit': null}));
                      _save();
                    },
              icon: const Icon(Icons.playlist_add),
              label: Text(tr('Ajouter une ligne')),
            ),
        ]),
        const SizedBox(height: 12),
        Card(
          clipBehavior: Clip.antiAlias,
          child: SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                // En-têtes d'offres
                Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  const SizedBox(width: 380),
                  for (final o in _offers)
                    SizedBox(
                      width: colW,
                      child: Padding(
                        padding: const EdgeInsets.all(4),
                        child: Column(children: [
                          if (open)
                            DropdownButtonFormField<String>(
                              isExpanded: true,
                              initialValue: o['partner_id'] as String?,
                              decoration: InputDecoration(isDense: true, labelText: tr('Fournisseur')),
                              items: [
                                if (o['partner_id'] != null && !suppliers.any((p) => p['id'] == o['partner_id'])) DropdownMenuItem(value: '${o['partner_id']}', child: Text(tr('${o['partner_name']}'), overflow: TextOverflow.ellipsis)),
                                for (final p in suppliers) DropdownMenuItem(value: '${p['id']}', child: Text(tr('${p['name']}'), overflow: TextOverflow.ellipsis)),
                              ],
                              onChanged: (v) => setState(() => o['partner_id'] = v),
                            )
                          else
                            Text(tr('${o['partner_name']}'), style: const TextStyle(fontWeight: FontWeight.w800), textAlign: TextAlign.center),
                          if (o['source'] == 'online')
                            Tooltip(
                              message: tr('Offre saisie par le fournisseur${o['submitted_at'] != null ? ' le ${DateFormat('dd/MM/yyyy à HH:mm').format(DateTime.parse('${o['submitted_at']}'))}' : ''}${'${o['notes'] ?? ''}'.isEmpty ? '' : '\n${o['notes']}'}${o['validity'] != null ? '\nValable jusqu\'au ${dateFr(o['validity'])}' : ''}'),
                              child: Padding(padding: EdgeInsets.only(top: 2), child: StatusChip(tr('Réponse en ligne'), color: YColors.blue)),
                            ),
                          Row(children: [
                            Expanded(
                              child: DropdownButtonFormField<String>(
                                isDense: true,
                                initialValue: '${o['currency']}',
                                items: [for (final x in {cur, 'EUR', 'USD', 'CNY', '${o['currency']}'}) DropdownMenuItem(value: x, child: Text(tr(x)))],
                                onChanged: open ? (v) => setState(() => o['currency'] = v ?? cur) : null,
                              ),
                            ),
                            if (o['currency'] != cur) SizedBox(width: 60, child: TextField(controller: o['rate'], enabled: open, inputFormatters: _num, decoration: InputDecoration(isDense: true, labelText: tr('cours')))),
                          ]),
                        ]),
                      ),
                    ),
                ]),
                const Divider(),
                for (var i = 0; i < _lines.length; i++)
                  Row(children: [
                    SizedBox(
                      width: 300,
                      child: TextField(controller: _lines[i]['description'], enabled: open, decoration: InputDecoration(isDense: true, prefixIcon: _lines[i]['product_id'] != null ? const Icon(Icons.link, size: 16) : null)),
                    ),
                    const SizedBox(width: 8),
                    SizedBox(width: 72, child: TextField(controller: _lines[i]['quantity'], enabled: open, inputFormatters: _num, textAlign: TextAlign.right, decoration: InputDecoration(isDense: true, labelText: tr('Qté')))),
                    for (final o in _offers) priceCell(o, _lines[i]),
                  ]),
                const Divider(),
                for (final row in [
                  ('Délai (jours)', 'delay'),
                  ('Frais (transport…) $cur', 'extra'),
                  ('Conditions', 'terms'),
                ])
                  Row(children: [
                    SizedBox(width: 380, child: Text(tr(row.$1), style: TextStyle(color: hint))),
                    for (final o in _offers)
                      SizedBox(width: colW, child: Padding(padding: const EdgeInsets.all(4), child: TextField(controller: o[row.$2], enabled: open, textAlign: row.$2 == 'terms' ? TextAlign.left : TextAlign.right, decoration: const InputDecoration(isDense: true)))),
                  ]),
                Row(children: [
                  SizedBox(width: 380, child: Text(tr('Conforme au cahier des charges'), style: TextStyle(color: hint))),
                  for (final o in _offers) SizedBox(width: colW, child: Checkbox(value: o['compliant'] == true, onChanged: open ? (v) => setState(() => o['compliant'] = v ?? true) : null)),
                ]),
                const Divider(),
                Row(children: [
                  SizedBox(width: 380, child: Text(tr('TOTAL en $cur'), style: const TextStyle(fontWeight: FontWeight.w800))),
                  for (final o in _offers)
                    SizedBox(
                      width: colW,
                      child: Padding(
                        padding: const EdgeInsets.all(6),
                        child: Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
                          Text(tr(totals['${o['key']}'] == null ? '—' : money(toDouble(totals['${o['key']}']!['total']), cur)), style: TextStyle(fontWeight: FontWeight.w800, color: a['cheapest'] == o['key'] ? YColors.success : null)),
                          if (totals['${o['key']}']?['complete'] == false) Text(tr('incomplète'), style: TextStyle(color: YColors.danger, fontSize: 11.5)),
                          Wrap(spacing: 4, children: [
                            if (a['cheapest'] == o['key'] && o['key'] != null) StatusChip(tr('Moins chère'), color: YColors.success),
                            if (a['fastest'] == o['key'] && o['key'] != null) StatusChip(tr('Plus rapide'), color: YColors.blue),
                            if (c['awarded'] == o['key'] && o['key'] != null) StatusChip(tr('Retenue'), color: YColors.success),
                          ]),
                          if (open && o['key'] != null)
                            TextButton(
                              onPressed: _busy
                                  ? null
                                  : () async {
                                      final note = TextEditingController();
                                      final ok = await showDialog<bool>(
                                        context: context,
                                        builder: (dc) => YAlertDialog(
                                          title: Text(tr('Retenir ${o['partner_name'] ?? 'cette offre'} ?')),
                                          content: TextField(controller: note, decoration: InputDecoration(labelText: tr('Motif du choix (prix, délai, qualité…)'))),
                                          actions: [TextButton(onPressed: () => Navigator.pop(dc, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(dc, true), child: Text(tr('Retenir et commander')))],
                                        ),
                                      );
                                      if (ok == true) await _post('award', {'offer': o['key'], 'note': note.text.trim()}, 'Offre retenue : bon de commande préparé.');
                                    },
                              child: Text(tr('Retenir')),
                            ),
                        ]),
                      ),
                    ),
                ]),
              ]),
            ),
          ),
        ),
        const SizedBox(height: 8),
        Text(tr('En vert : le meilleur prix de chaque ligne. En panachant les meilleurs prix : ${money(toDouble(a['best_mixed_total']), cur)}.'), style: TextStyle(color: hint, fontSize: 12.5)),
        const SizedBox(height: 12),
        _consultation(c),
        if (c['history'] is Map && (c['history'] as Map).isNotEmpty) ...[
          const SizedBox(height: 12),
          SectionCard(
            title: tr('Derniers prix payés'),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              for (final e in asMap(c['history']).entries) ...[
                Text(tr(_lines.where((l) => l['product_id'] == e.key).map((l) => (l['description'] as TextEditingController).text).firstOrNull ?? ''), style: const TextStyle(fontWeight: FontWeight.w700)),
                for (final h in List<Map>.from(e.value as List))
                  Padding(padding: const EdgeInsets.only(left: 12, top: 2), child: Text(tr('${h['partner']} : ${money(toDouble(h['last_price']), cur)} le ${dateFr(h['last_date'])} (${h['count']} achat(s), minimum ${money(toDouble(h['min']), cur)})'), style: TextStyle(color: hint))),
                const SizedBox(height: 6),
              ],
            ]),
          ),
        ],
      ]),
    );
  }
}

// ---------------------------------------------------------------------------------------------- cahiers des charges

class _SpecsTab extends StatefulWidget {
  const _SpecsTab({super.key, required this.meta, required this.manage, required this.onChanged});

  final Map<String, dynamic> meta;
  final bool manage;
  final VoidCallback onChanged;

  @override
  State<_SpecsTab> createState() => _SpecsTabState();
}

class _SpecsTabState extends State<_SpecsTab> {
  String _search = '';
  String _status = '';
  Map<String, String?> _f = {};
  FilterPeriod? _period;

  static const _statuses = {'': 'Tous', 'draft': 'Brouillons', 'published': 'Envoyés'};

  static bool _statusOk(Map<String, dynamic> s, String st) => st.isEmpty || (st == 'published') == (s['status'] == 'published');

  @override
  Widget build(BuildContext context) {
    final meta = widget.meta, manage = widget.manage;
    final hint = Theme.of(context).hintColor;
    return _ApiList(
      path: 'appro/specs',
      builder: (c, all, reload) {
        final base = all.where((s) {
          if (_search.isNotEmpty && !'${s['number']} ${s['title']} ${s['site'] ?? ''}'.toLowerCase().contains(_search)) return false;
          final site = _f['site'];
          if (site != null && '${s['site'] ?? ''}' != site) return false;
          if (_period != null && !_period!.contains(s['deadline'])) return false;
          return true;
        }).toList();
        final rows = base.where((s) => _statusOk(s, _status)).toList();
        return ListView(padding: const EdgeInsets.all(16), children: [
        FilterBar(
          padding: const EdgeInsets.only(bottom: 12),
          search: _search,
          searchHint: 'N°, objet, site…',
          onSearch: (v) => setState(() => _search = v.toLowerCase()),
          statuses: _statuses,
          status: _status,
          onStatus: (v) => setState(() => _status = v),
          statusCounts: {for (final k in _statuses.keys) k: base.where((s) => _statusOk(s, k)).length},
          filters: [FilterSpec('site', 'Site', filterOptions(all, 'site'), icon: Icons.place_outlined)],
          values: _f,
          onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
          showPeriod: true,
          period: _period,
          onPeriod: (v) => setState(() => _period = v),
          count: rows.length,
          countLabel: 'cahier',
          onReset: () => setState(() => (_search = '', _status = '', _f = {}, _period = null)),
          trailing: [
            if (manage) FilledButton.icon(onPressed: () => _openSpec(context, meta, null).then((_) => reload()), icon: const Icon(Icons.add), label: Text(tr('Nouveau cahier des charges'))),
          ],
        ),
        if (rows.isEmpty && all.isNotEmpty) EmptyState(icon: Icons.search_off, title: tr('Aucun résultat'))
        else if (rows.isEmpty) EmptyState(icon: Icons.description_outlined, title: tr('Aucun cahier des charges'), message: tr('Décrivez le besoin (normes, quantités, délais, garanties), envoyez-le en PDF aux fournisseurs, puis comparez leurs offres.')),
        for (final s in rows)
          Card(
            child: ListTile(
              onTap: () => _openSpec(context, meta, s['id'] as int).then((_) => reload()),
              leading: CircleAvatar(backgroundColor: YColors.blue.withValues(alpha: .1), child: const Icon(Icons.description_outlined, color: YColors.blue)),
              title: Text(tr('${s['number']} · ${s['title']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
              subtitle: Text(tr([
                if (s['site'] != null) '${s['site']}',
                '${(s['requirements'] as List? ?? const []).length} spécification(s)',
                if (s['deadline'] != null) 'offres avant le ${dateFr(s['deadline'])}',
                if ((s['sent_to'] as List? ?? const []).isNotEmpty) 'envoyé à ${(s['sent_to'] as List).length} fournisseur(s)',
              ].join(' · ')), style: TextStyle(color: hint)),
              trailing: StatusChip(s['status'] == 'published' ? 'Envoyé' : 'Brouillon', color: s['status'] == 'published' ? YColors.success : YColors.muted),
            ),
          ),
        ]);
      },
    );
  }
}

Future<void> _openSpec(BuildContext context, Map<String, dynamic> meta, int? id) => Navigator.of(context).push(MaterialPageRoute(builder: (_) => _SpecPage(meta: meta, id: id)));

class _SpecPage extends StatefulWidget {
  const _SpecPage({required this.meta, this.id});

  final Map<String, dynamic> meta;
  final int? id;

  @override
  State<_SpecPage> createState() => _SpecPageState();
}

class _SpecPageState extends State<_SpecPage> {
  late int? _id = widget.id;
  Map<String, dynamic>? _s;
  bool _busy = false;
  final _title = TextEditingController();
  final _site = TextEditingController();
  final _context = TextEditingController();
  String? _deadline;
  List<(TextEditingController, TextEditingController)> _sections = [];
  List<Map<String, dynamic>> _reqs = [];

  @override
  void initState() {
    super.initState();
    if (_id != null) {
      _load();
    } else {
      _sections = [for (final x in widget.meta['spec_sections'] as List) (TextEditingController(text: '${(x as Map)['title']}'), TextEditingController(text: '${x['body']}'))];
      _reqs = [_req()];
    }
  }

  Map<String, dynamic> _req([Map? r]) => {'label': TextEditingController(text: '${r?['label'] ?? ''}'), 'value': TextEditingController(text: '${r?['value'] ?? ''}'), 'quantity': TextEditingController(text: r?['quantity'] == null ? '' : _q(r!['quantity'])),
        'unit': TextEditingController(text: '${r?['unit'] ?? ''}'), 'mandatory': r?['mandatory'] != false};

  Future<void> _load() async {
    final r = await context.read<Session>().api.get('appro/specs/$_id');
    _fill(asMap(r['data']));
  }

  void _fill(Map<String, dynamic> s) => setState(() {
        _s = s;
        _id = s['id'] as int;
        _title.text = '${s['title']}';
        _site.text = '${s['site'] ?? ''}';
        _context.text = '${s['context'] ?? ''}';
        _deadline = s['deadline'] as String?;
        _sections = [for (final x in s['sections'] as List? ?? const []) (TextEditingController(text: '${(x as Map)['title']}'), TextEditingController(text: '${x['body']}'))];
        _reqs = [for (final r in s['requirements'] as List? ?? const []) _req(r as Map)];
      });

  Future<bool> _save() async {
    setState(() => _busy = true);
    final body = {
      'title': _title.text.trim(), 'site': _site.text.trim(), 'context': _context.text.trim(), 'deadline': _deadline,
      'sections': [for (final x in _sections) {'title': x.$1.text, 'body': x.$2.text}],
      'requirements': [for (final r in _reqs) {'label': (r['label'] as TextEditingController).text, 'value': (r['value'] as TextEditingController).text, 'quantity': (r['quantity'] as TextEditingController).text.isEmpty ? null : toDouble((r['quantity'] as TextEditingController).text), 'unit': (r['unit'] as TextEditingController).text, 'mandatory': r['mandatory']}],
    };
    try {
      final api = context.read<Session>().api;
      final r = _id == null ? await api.post('appro/specs', body) : await api.put('appro/specs/$_id', body);
      _fill(asMap(r['data']));
      if (mounted) toast(context, tr('Cahier des charges enregistré.'));
      return true;
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
      return false;
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _send() async {
    if (!await _save() || !mounted) return;
    final list = _suppliers();
    final picked = <String>{};
    final msg = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (dc) => StatefulBuilder(
        builder: (dc, set) => YAlertDialog(
          title: Text(tr('Envoyer aux fournisseurs')),
          content: SizedBox(
            width: 480,
            height: 460,
            child: Column(children: [
              Expanded(
                child: ListView(children: [
                  for (final p in list)
                    CheckboxListTile(
                      dense: true,
                      value: picked.contains(p['id']),
                      title: Text(tr('${p['name']}')),
                      subtitle: Text(tr('${p['email'] ?? 'pas d\'e-mail'}')),
                      onChanged: (v) => set(() => v == true ? picked.add('${p['id']}') : picked.remove(p['id'])),
                    ),
                ]),
              ),
              TextField(controller: msg, maxLines: 3, decoration: InputDecoration(labelText: tr('Message (facultatif)'))),
            ]),
          ),
          actions: [TextButton(onPressed: () => Navigator.pop(dc, false), child: Text(tr('Annuler'))), FilledButton(onPressed: picked.isEmpty ? null : () => Navigator.pop(dc, true), child: Text(tr('Envoyer (${picked.length})')))],
        ),
      ),
    );
    if (ok != true || !mounted) return;
    try {
      final r = await context.read<Session>().api.post('appro/specs/$_id/send', {'partner_ids': picked.toList(), 'message': msg.text.trim().isEmpty ? null : msg.text.trim()});
      final skipped = List<String>.from(r['data']['skipped'] as List);
      if (mounted) toast(context, tr('${r['data']['sent']} envoi(s)${skipped.isEmpty ? '' : ' · sans e-mail : ${skipped.join(', ')}'}.'));
      await _load();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _compare() async {
    if (!await _save() || !mounted) return;
    try {
      final r = await context.read<Session>().api.post('appro/comparisons', {'spec_id': _id});
      if (mounted) await _openComparison(context, r['data']['id'] as int);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = _s;
    final hint = Theme.of(context).hintColor;
    return Scaffold(
      appBar: AppBar(title: Text(tr(s == null ? 'Nouveau cahier des charges' : '${s['number']} · ${s['title']}')), actions: [
        if (_id != null) IconButton(tooltip: tr('PDF'), onPressed: () => _openPdf(context, 'appro/specs/$_id/pdf', 'Cahier des charges ${s?['number']}'), icon: const Icon(Icons.picture_as_pdf_outlined)),
        if (_id != null) TextButton.icon(onPressed: _busy ? null : _compare, icon: const Icon(Icons.compare_arrows), label: Text(tr('Comparer les offres'))),
        if (_id != null) TextButton.icon(onPressed: _busy ? null : _send, icon: const Icon(Icons.send), label: Text(tr('Envoyer'))),
        Padding(padding: const EdgeInsets.only(right: 12), child: FilledButton(onPressed: _busy ? null : _save, child: Text(tr('Enregistrer')))),
      ]),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        if ((s?['sent_to'] as List? ?? const []).isNotEmpty)
          Card(child: ListTile(leading: const Icon(Icons.mark_email_read_outlined, color: YColors.success), title: Text(tr('Envoyé à : ${(s!['sent_to'] as List).map((x) => (x as Map)['name']).join(', ')}')))),
        SectionCard(
          child: Column(children: [
            TextField(controller: _title, decoration: InputDecoration(labelText: tr('Intitulé (ex. Électrification du bâtiment R+3)'))),
            Row(children: [
              Expanded(child: TextField(controller: _site, decoration: InputDecoration(labelText: tr('Chantier / lieu')))),
              const SizedBox(width: 12),
              _dateButton(context, 'Remise des offres', _deadline, (v) => setState(() => _deadline = v)),
            ]),
            TextField(controller: _context, maxLines: 3, decoration: InputDecoration(labelText: tr('Contexte (facultatif)'), alignLabelWithHint: true)),
          ]),
        ),
        const SizedBox(height: 12),
        SectionCard(
          title: tr('Spécifications et quantités'),
          trailing: TextButton.icon(onPressed: () => setState(() => _reqs.add(_req())), icon: const Icon(Icons.add, size: 18), label: Text(tr('Ligne'))),
          child: Column(children: [
            for (var i = 0; i < _reqs.length; i++)
              Row(children: [
                Expanded(flex: 3, child: TextField(controller: _reqs[i]['label'], decoration: InputDecoration(isDense: true, labelText: tr('Désignation')))),
                const SizedBox(width: 8),
                Expanded(flex: 4, child: TextField(controller: _reqs[i]['value'], decoration: InputDecoration(isDense: true, labelText: tr('Caractéristiques exigées (norme, calibre…)')))),
                const SizedBox(width: 8),
                SizedBox(width: 70, child: TextField(controller: _reqs[i]['quantity'], inputFormatters: _num, textAlign: TextAlign.right, decoration: InputDecoration(isDense: true, labelText: tr('Qté')))),
                const SizedBox(width: 8),
                SizedBox(width: 80, child: TextField(controller: _reqs[i]['unit'], decoration: InputDecoration(isDense: true, labelText: tr('Unité')))),
                Tooltip(message: tr('Obligatoire'), child: Checkbox(value: _reqs[i]['mandatory'] == true, onChanged: (v) => setState(() => _reqs[i]['mandatory'] = v ?? true))),
                IconButton(onPressed: () => setState(() => _reqs.removeAt(i)), icon: const Icon(Icons.close, size: 18)),
              ]),
          ]),
        ),
        const SizedBox(height: 12),
        SectionCard(
          title: tr('Rubriques'),
          trailing: TextButton.icon(onPressed: () => setState(() => _sections.add((TextEditingController(), TextEditingController()))), icon: const Icon(Icons.add, size: 18), label: Text(tr('Rubrique'))),
          child: Column(children: [
            for (var i = 0; i < _sections.length; i++)
              Padding(
                padding: const EdgeInsets.only(bottom: 10),
                child: Column(children: [
                  Row(children: [
                    Expanded(child: TextField(controller: _sections[i].$1, style: const TextStyle(fontWeight: FontWeight.w700), decoration: InputDecoration(isDense: true, prefixText: '${i + 1}. '))),
                    IconButton(onPressed: () => setState(() => _sections.removeAt(i)), icon: const Icon(Icons.close, size: 18)),
                  ]),
                  TextField(controller: _sections[i].$2, maxLines: null, minLines: 2, decoration: InputDecoration(isDense: true, hintText: tr('Texte (vide : la rubrique n\'apparaît pas dans le PDF)'))),
                ]),
              ),
            Text(tr('Les rubriques vides ne sont pas imprimées.'), style: TextStyle(color: hint, fontSize: 12)),
          ]),
        ),
      ]),
    );
  }
}

// ---------------------------------------------------------------------------------------------- locations

Color _rentalColor(Map r) => r['overdue'] == true
    ? YColors.danger
    : switch ('${r['status']}') { 'active' => YColors.success, 'planned' => YColors.blue, 'returned' => YColors.warning, _ => YColors.muted };

class _RentalsTab extends StatefulWidget {
  const _RentalsTab({super.key, required this.meta, required this.manage, required this.onChanged});

  final Map<String, dynamic> meta;
  final bool manage;
  final VoidCallback onChanged;

  @override
  State<_RentalsTab> createState() => _RentalsTabState();
}

class _RentalsTabState extends State<_RentalsTab> {
  String _status = 'open';
  String _search = '';
  Map<String, String?> _f = {};
  FilterPeriod? _period;

  static const _statuses = {'': 'Toutes', 'open': 'En cours et prévues', 'returned': 'À facturer', 'closed': 'Facturées'};

  @override
  Widget build(BuildContext context) {
    final cur = context.watch<Session>().currency;
    final hint = Theme.of(context).hintColor;
    return _ApiList(
      path: 'appro/rentals',
      query: {'status': _status},
      builder: (c, all, reload) {
        final rows = all.where((r) {
          if (_search.isNotEmpty && !'${r['number']} ${r['title']} ${r['partner'] ?? ''} ${r['site'] ?? ''}'.toLowerCase().contains(_search)) return false;
          final k = _f['kind'], sup = _f['supplier'], site = _f['site'], due = _f['due'];
          if (k != null && '${r['kind']}' != k) return false;
          if (sup != null && '${r['partner'] ?? ''}' != sup) return false;
          if (site != null && '${r['site'] ?? ''}' != site) return false;
          if (due == 'overdue' && r['overdue'] != true) return false;
          if (due == 'ending' && !(r['overdue'] != true && r['days_left'] != null && toDouble(r['days_left']) >= 0 && toDouble(r['days_left']) <= 7)) return false;
          if (_period != null && !_period!.contains(r['start_date'])) return false;
          return true;
        }).toList();
        return ListView(padding: const EdgeInsets.all(16), children: [
        FilterBar(
          padding: const EdgeInsets.only(bottom: 12),
          search: _search,
          searchHint: 'N°, objet, fournisseur, site…',
          onSearch: (v) => setState(() => _search = v.toLowerCase()),
          statuses: _statuses,
          status: _status,
          onStatus: (v) => setState(() => _status = v),
          filters: [
            FilterSpec('kind', 'Type', filterOptions(all, 'kind', label: (r) => '${r['kind_label'] ?? r['kind']}'), icon: Icons.category_outlined),
            FilterSpec('supplier', 'Fournisseur', filterOptions(all, 'partner'), icon: Icons.local_shipping_outlined),
            FilterSpec('site', 'Site', filterOptions(all, 'site'), icon: Icons.place_outlined),
            const FilterSpec('due', 'Échéance', {'overdue': 'Dépassées', 'ending': 'Se terminent sous 7 jours'}, icon: Icons.event_busy),
          ],
          values: _f,
          onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
          showPeriod: true,
          period: _period,
          onPeriod: (v) => setState(() => _period = v),
          count: rows.length,
          countLabel: 'location',
          onReset: () => setState(() => (_search = '', _status = 'open', _f = {}, _period = null)),
          trailing: [
            TextButton.icon(onPressed: () => _saveXlsx(context, 'appro/rentals/xlsx', 'locations-et-services-externes'), icon: const Icon(Icons.grid_on_outlined, size: 18), label: Text(tr('Excel'))),
            if (widget.manage) FilledButton.icon(onPressed: () => _editRental(context, widget.meta, null).then((_) => reload()), icon: const Icon(Icons.add), label: Text(tr('Nouvelle location / prestation'))),
          ],
        ),
        if (rows.isEmpty && all.isNotEmpty) EmptyState(icon: Icons.search_off, title: tr('Aucun résultat'))
        else if (rows.isEmpty) EmptyState(icon: Icons.construction_outlined, title: tr('Aucune location'), message: tr('Nacelle, groupe électrogène, engin, électricien intérimaire, sous-traitant : suivez la durée, le coût, les prolongations et le retour.')),
        for (final r in rows)
          Card(
            child: InkWell(
              borderRadius: BorderRadius.circular(16),
              onTap: () => _rentalDetail(context, widget.meta, r['id'] as int, widget.manage).then((_) => reload()),
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: Row(children: [
                  CircleAvatar(backgroundColor: _rentalColor(r).withValues(alpha: .12), child: Icon(r['kind'] == 'service' || r['kind'] == 'subcontract' ? Icons.engineering_outlined : Icons.construction_outlined, color: _rentalColor(r))),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(tr('${r['number']} · ${r['title']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                      Text(
                        tr([
                          '${r['kind_label']}',
                          if (r['partner'] != null) '${r['partner']}',
                          if (r['site'] != null) '${r['site']}',
                          'du ${dateFr(r['start_date'])} au ${dateFr(r['returned_at'] ?? r['end_date'])}',
                          '${money(toDouble(r['rate']), cur)} / ${r['unit_label']}',
                        ].join(' · ')),
                        style: TextStyle(color: hint, fontSize: 12.5),
                      ),
                      if (r['overdue'] == true) Text(tr('Dépassée de ${r['overdue_days']} jour(s) : à rendre ou à prolonger'), style: const TextStyle(color: YColors.danger, fontWeight: FontWeight.w700, fontSize: 12.5))
                      else if (r['days_left'] != null && toDouble(r['days_left']) <= 2 && toDouble(r['days_left']) >= 0) Text(tr('Se termine dans ${r['days_left']} jour(s)'), style: const TextStyle(color: YColors.warning, fontWeight: FontWeight.w700, fontSize: 12.5)),
                    ]),
                  ),
                  Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
                    Text(tr(money(toDouble(r['amount']), cur)), style: const TextStyle(fontWeight: FontWeight.w800)),
                    Text(tr('${r['days']} jour(s)'), style: TextStyle(color: hint, fontSize: 12)),
                    const SizedBox(height: 4),
                    StatusChip(r['overdue'] == true ? 'Dépassée' : '${r['status_label']}', color: _rentalColor(r)),
                  ]),
                ]),
              ),
            ),
          ),
        ]);
      },
    );
  }
}

Future<void> _editRental(BuildContext context, Map<String, dynamic> meta, Map? r) async {
  final kinds = asMap(meta['rental_kinds']);
  final units = asMap(meta['rental_units']);
  var kind = '${r?['kind'] ?? 'equipment'}';
  var unit = '${r?['rate_unit'] ?? 'day'}';
  String? partner = r?['partner_id'] as String?;
  String? start = '${r?['start_date'] ?? isoDate(DateTime.now())}';
  String? end = '${r?['end_date'] ?? isoDate(DateTime.now().add(const Duration(days: 6)))}';
  final title = TextEditingController(text: '${r?['title'] ?? ''}');
  final rate = TextEditingController(text: r == null ? '' : '${toDouble(r['rate']).round()}');
  final qty = TextEditingController(text: r == null ? '1' : _q(r['quantity']));
  final deposit = TextEditingController(text: r == null || toDouble(r['deposit']) == 0 ? '' : '${toDouble(r['deposit']).round()}');
  final site = TextEditingController(text: '${r?['site'] ?? ''}');
  final ref = TextEditingController(text: '${r?['reference'] ?? ''}');
  final notes = TextEditingController(text: '${r?['notes'] ?? ''}');
  final cur = context.read<Session>().currency;
  final ok = await showFormDialog<bool>(context, title: r == null ? 'Nouvelle location / prestation externe' : 'Modifier ${r['number']}', maxWidth: 668, builder: (dc) => StatefulBuilder(
      builder: (dc, set) => SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
          Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            DropdownButtonFormField<String>(initialValue: kind, decoration: InputDecoration(labelText: tr('Type')), items: [for (final e in kinds.entries) DropdownMenuItem(value: e.key, child: Text(tr('${e.value}')))], onChanged: (v) => set(() => kind = v ?? 'equipment')),
            TextField(controller: title, decoration: InputDecoration(labelText: tr('Objet (ex. Nacelle ciseaux 12 m, électricien intérimaire)'))),
            _supplierPicker(partner, (v) => set(() => partner = v), label: tr('Loueur / prestataire'), name: r?['partner'] as String?),
            const SizedBox(height: 8),
            Wrap(spacing: 8, children: [_dateButton(dc, 'Début', start, (v) => set(() => start = v), clear: false), _dateButton(dc, 'Fin prévue', end, (v) => set(() => end = v), clear: false)]),
            Row(children: [
              Expanded(child: TextField(controller: rate, inputFormatters: _num, decoration: InputDecoration(labelText: tr('Tarif HT ($cur)')))),
              const SizedBox(width: 8),
              SizedBox(width: 140, child: DropdownButtonFormField<String>(initialValue: unit, decoration: InputDecoration(labelText: tr('par')), items: [for (final e in units.entries) DropdownMenuItem(value: e.key, child: Text(tr('${e.value}')))], onChanged: (v) => set(() => unit = v ?? 'day'))),
              const SizedBox(width: 8),
              SizedBox(width: 90, child: TextField(controller: qty, inputFormatters: _num, decoration: InputDecoration(labelText: trn(unit == 'hour' ? 'Heures' : 'Quantité')))),
            ]),
            Row(children: [
              Expanded(child: TextField(controller: deposit, inputFormatters: _num, decoration: InputDecoration(labelText: tr('Caution versée')))),
              const SizedBox(width: 8),
              Expanded(child: TextField(controller: ref, decoration: InputDecoration(labelText: tr('N° de contrat / bon')))),
            ]),
            TextField(controller: site, decoration: InputDecoration(labelText: tr('Chantier / lieu d\'utilisation'))),
            TextField(controller: notes, maxLines: 2, decoration: InputDecoration(labelText: tr('Notes'))),
          ]),
          const SizedBox(height: 20),
          Align(
            alignment: Alignment.centerRight,
            child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
              TextButton(onPressed: () => Navigator.pop(dc, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(dc, true), child: Text(tr('Enregistrer'))),
            ]),
          ),
        ]),
      ),
    ));
  if (ok != true || !context.mounted) return;
  final body = {'kind': kind, 'title': title.text.trim(), 'partner_id': partner, 'start_date': start, 'end_date': end, 'rate': toDouble(rate.text), 'rate_unit': unit, 'quantity': toDouble(qty.text),
    'deposit': toDouble(deposit.text), 'site': site.text.trim(), 'reference': ref.text.trim(), 'notes': notes.text.trim()};
  try {
    final api = context.read<Session>().api;
    r == null ? await api.post('appro/rentals', body) : await api.put('appro/rentals/${r['id']}', body);
  } catch (e) {
    if (context.mounted) toast(context, errorText(e), error: true);
  }
}

Future<void> _rentalDetail(BuildContext context, Map<String, dynamic> meta, int id, bool manage) async {
  final api = context.read<Session>().api;
  final cur = context.read<Session>().currency;
  Map<String, dynamic> r = asMap((await api.get('appro/rentals/$id'))['data']);
  if (!context.mounted) return;
  await showDialog(
    context: context,
    builder: (dc) => StatefulBuilder(builder: (dc, set) {
      Future<void> act(String a, Map<String, dynamic> body, String done) async {
        try {
          final x = await api.post('appro/rentals/$id/$a', body);
          set(() => r = asMap(x['data']));
          if (dc.mounted) toast(dc, done);
        } catch (e) {
          if (dc.mounted) toast(dc, errorText(e), error: true);
        }
      }

      final hint = Theme.of(dc).hintColor;
      final open = ['planned', 'active'].contains(r['status']);
      return YAlertDialog(
        title: Row(children: [Expanded(child: Text(tr('${r['number']} · ${r['title']}'))), StatusChip(r['overdue'] == true ? 'Dépassée' : '${r['status_label']}', color: _rentalColor(r))]),
        content: SizedBox(
          width: 620,
          child: SingleChildScrollView(
            child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(tr('${r['kind_label']}${r['partner'] != null ? ' · ${r['partner']}' : ''}${r['site'] != null ? ' · ${r['site']}' : ''}'), style: TextStyle(color: hint)),
              const SizedBox(height: 8),
              Text(tr('Du ${dateFr(r['start_date'])} au ${dateFr(r['end_date'])}${r['returned_at'] != null ? ' · rendu le ${dateFr(r['returned_at'])}' : ''}')),
              Text(tr('${money(toDouble(r['rate']), cur)} / ${r['unit_label']} × ${_q(r['quantity'])} · ${r['days']} jour(s) · ${_q(r['units'])} ${r['unit_label']}(s) facturé(s)')),
              const SizedBox(height: 6),
              Text(tr('Coût : ${money(toDouble(r['amount']), cur)} HT${toDouble(r['planned_amount']) != toDouble(r['amount']) ? ' (prévu ${money(toDouble(r['planned_amount']), cur)})' : ''}'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
              if (toDouble(r['deposit']) > 0) Text(tr('Caution ${money(toDouble(r['deposit']), cur)}${r['deposit_returned'] == true ? ' · restituée' : ''}')),
              if (r['invoice_number'] != null) Text(tr('Facture fournisseur ${r['invoice_number']} (brouillon dans les Achats)')),
              const Divider(height: 22),
              Text(tr('Historique'), style: TextStyle(fontWeight: FontWeight.w700)),
              for (final e in List<Map>.from(r['events'] as List))
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 3),
                  child: Text(
                    tr('${DateFormat('dd/MM/yyyy').format(DateTime.parse('${e['at']}').toLocal())} · ${{'created': 'Création', 'extend': 'Prolongation', 'return': 'Retour', 'invoice': 'Facture', 'cancel': 'Annulation'}[e['type']] ?? e['type']}'
                    '${e['type'] == 'extend' ? ' jusqu\'au ${dateFr(e['data']?['to'])}' : ''}${e['type'] == 'return' || e['type'] == 'invoice' ? ' · ${money(toDouble(e['data']?['amount']), cur)}' : ''}${e['user'] != null ? ' · ${e['user']}' : ''}${e['body'] != null ? '\n   ${e['body']}' : ''}'),
                    style: TextStyle(color: hint, fontSize: 12.5),
                  ),
                ),
            ]),
          ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(dc), child: Text(tr('Fermer'))),
          if (manage && open) ...[
            TextButton(onPressed: () async {
              Navigator.pop(dc);
              await _editRental(context, meta, r);
            }, child: Text(tr('Modifier'))),
            OutlinedButton(
              onPressed: () async {
                final d = await pickDate(dc, firstDate: DateTime.parse('${r['end_date']}').add(const Duration(days: 1)), lastDate: DateTime(2100), initialDate: DateTime.parse('${r['end_date']}').add(const Duration(days: 7)));
                if (d != null) await act('extend', {'end_date': isoDate(d)}, 'Location prolongée.');
              },
              child: Text(tr('Prolonger')),
            ),
            FilledButton(
              onPressed: () async {
                var back = true;
                final ok = await showDialog<bool>(
                  context: dc,
                  builder: (c2) => StatefulBuilder(builder: (c2, s2) => YAlertDialog(
                        title: Text(tr('Retour du matériel / fin de la prestation')),
                        content: toDouble(r['deposit']) > 0 ? CheckboxListTile(value: back, title: Text(tr('Caution restituée')), onChanged: (v) => s2(() => back = v ?? false)) : Text(tr('Date de retour : aujourd\'hui.')),
                        actions: [TextButton(onPressed: () => Navigator.pop(c2, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(c2, true), child: Text(tr('Enregistrer le retour')))],
                      )),
                );
                if (ok == true) await act('return', {'date': isoDate(DateTime.now()), 'deposit_returned': back}, 'Retour enregistré.');
              },
              child: Text(tr('Retour')),
            ),
          ],
          if (manage && r['status'] == 'returned') FilledButton(onPressed: () => act('invoice', const {}, 'Facture fournisseur préparée (brouillon).'), child: Text(tr('Créer la facture fournisseur'))),
        ],
      );
    }),
  );
}

// ---------------------------------------------------------------------------------------------- fournisseurs

class _SuppliersTab extends StatefulWidget {
  const _SuppliersTab({super.key});

  @override
  State<_SuppliersTab> createState() => _SuppliersTabState();
}

class _SuppliersTabState extends State<_SuppliersTab> {
  String _search = '';
  Map<String, String?> _f = {};

  static String _scoreBand(Map<String, dynamic> s) {
    if (s['score'] == null) return 'none';
    final v = toDouble(s['score']);
    return v >= 4 ? 'good' : (v >= 3 ? 'mid' : 'low');
  }

  @override
  Widget build(BuildContext context) {
    final cur = context.watch<Session>().currency;
    final hint = Theme.of(context).hintColor;
    return _ApiList(
      path: 'appro/suppliers',
      builder: (c, all, _) {
        final rows = all.where((s) {
          if (_search.isNotEmpty && !'${s['name']} ${s['country'] ?? ''}'.toLowerCase().contains(_search)) return false;
          final co = _f['country'], sc = _f['score'], ot = _f['on_time'];
          if (co != null && '${s['country'] ?? ''}' != co) return false;
          if (sc != null && _scoreBand(s) != sc) return false;
          if (ot == 'late' && !(s['on_time'] != null && toDouble(s['on_time']) < 80)) return false;
          return true;
        }).toList();
        return ListView(padding: const EdgeInsets.all(16), children: [
        Row(children: [
          Expanded(child: Text(tr('Évaluation sur les 12 derniers mois : achats, délai moyen entre commande et réception, livraisons à l\'heure et complètes.'), style: TextStyle(color: hint))),
          TextButton.icon(onPressed: () => _saveXlsx(context, 'appro/suppliers/xlsx', 'evaluation-fournisseurs'), icon: const Icon(Icons.grid_on_outlined, size: 18), label: Text(tr('Excel'))),
        ]),
        const SizedBox(height: 8),
        if (all.isNotEmpty)
          FilterBar(
            padding: const EdgeInsets.only(bottom: 12),
            search: _search,
            searchHint: 'Fournisseur, pays…',
            onSearch: (v) => setState(() => _search = v.toLowerCase()),
            filters: [
              FilterSpec('country', 'Pays', filterOptions(all, 'country'), icon: Icons.public),
              const FilterSpec('score', 'Note', {'good': '4 et plus', 'mid': 'De 3 à 4', 'low': 'Moins de 3', 'none': 'Non noté'}, icon: Icons.star_outline_rounded),
              const FilterSpec('on_time', 'Ponctualité', {'late': 'Moins de 80 % à l\'heure'}, icon: Icons.schedule),
            ],
            values: _f,
            onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
            count: rows.length,
            countLabel: 'fournisseur',
            onReset: () => setState(() => (_search = '', _f = {})),
          ),
        if (rows.isEmpty && all.isNotEmpty) EmptyState(icon: Icons.search_off, title: tr('Aucun résultat'))
        else if (rows.isEmpty) EmptyState(icon: Icons.factory_outlined, title: tr('Pas encore d\'historique'), message: tr('L\'historique se construit avec vos bons de commande, réceptions et factures fournisseurs.')),
        if (rows.isNotEmpty)
          Card(
            clipBehavior: Clip.antiAlias,
            child: SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: DataTable(showCheckboxColumn: false, columnSpacing: 22, columns: [
                DataColumn(label: Text(tr('Fournisseur'))), DataColumn(label: Text(tr('Achats 12 mois')), numeric: true), DataColumn(label: Text(tr('Évolution')), numeric: true), DataColumn(label: Text(tr('Commandes')), numeric: true),
                DataColumn(label: Text(tr('Délai moyen')), numeric: true), DataColumn(label: Text(tr('À l\'heure')), numeric: true), DataColumn(label: Text(tr('Complètes')), numeric: true), DataColumn(label: Text(tr('Note')), numeric: true),
              ], rows: [
                for (final s in rows)
                  DataRow(onSelectChanged: (_) => _openSupplier(context, '${s['partner_id']}'), cells: [
                    DataCell(Text(tr('${s['name']}${s['country'] != null ? ' (${s['country']})' : ''}'), style: const TextStyle(fontWeight: FontWeight.w700))),
                    DataCell(Text(tr(money(toDouble(s['spend']), cur)))),
                    DataCell(Text(tr(toDouble(s['spend_prev']) > 0 ? '${toDouble(s['spend']) >= toDouble(s['spend_prev']) ? '+' : ''}${((toDouble(s['spend']) - toDouble(s['spend_prev'])) * 100 / toDouble(s['spend_prev'])).round()} %' : '—'), style: TextStyle(color: hint))),
                    DataCell(Text(tr('${s['orders']}'))),
                    DataCell(Text(tr(s['lead_days'] == null ? '—' : '${_q(s['lead_days'])} j'))),
                    DataCell(Text(tr(s['on_time'] == null ? '—' : '${s['on_time']} %'))),
                    DataCell(Text(tr(s['complete'] == null ? '—' : '${s['complete']} %'))),
                    DataCell(s['score'] == null ? const Text('—') : Row(mainAxisSize: MainAxisSize.min, children: [Icon(Icons.star_rounded, size: 16, color: toDouble(s['score']) >= 4 ? YColors.success : toDouble(s['score']) >= 3 ? YColors.warning : YColors.danger), Text(tr(' ${s['score']}'))])),
                  ]),
              ]),
            ),
          ),
        ]);
      },
    );
  }
}

Future<void> _openSupplier(BuildContext context, String id) => Navigator.of(context).push(MaterialPageRoute(builder: (_) => _SupplierPage(id: id)));

class _SupplierPage extends StatelessWidget {
  const _SupplierPage({required this.id});

  final String id;

  @override
  Widget build(BuildContext context) {
    final cur = context.watch<Session>().currency;
    return Scaffold(
      appBar: AppBar(title: Text(tr('Historique du fournisseur'))),
      body: FutureBuilder(
        future: context.read<Session>().api.get('appro/suppliers/$id'),
        builder: (c, snap) {
          if (snap.hasError) return OnlineOnlyNotice(error: snap.error!, onRetry: () {});
          if (!snap.hasData) return const LoadingSkeleton();
          final d = asMap((snap.data as Map)['data']);
          final p = asMap(d['partner']);
          final k = asMap(d['kpis']);
          final hint = Theme.of(c).hintColor;
          final months = List<Map>.from(d['months'] as List);
          final chart = ChartData([for (final m in months) DateFormat('MMM yy', I18n.dateLocale).format(DateTime.parse('${m['month']}-01'))], [ChartSeries('Achats', [for (final m in months) toDouble(m['total'])])], 'money');
          return ListView(padding: const EdgeInsets.all(16), children: [
            Text(tr('${p['name']}'), style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800)),
            Text(tr([if (p['aux_code'] != null) '${p['aux_code']}', if (p['city'] != null) '${p['city']}', if (p['country'] != null) '${p['country']}', if (p['phone'] != null) '${p['phone']}', if (p['email'] != null) '${p['email']}', if (p['since'] != null) 'fournisseur depuis ${dateFr(p['since'])}'].join(' · ')), style: TextStyle(color: hint)),
            const SizedBox(height: 12),
            KpiWrap(children: [
              _kpi(c, 'Achats au total', money(toDouble(k['total']), cur), Icons.shopping_cart_outlined, YColors.blue, sub: '${k['invoices']} facture(s)'),
              _kpi(c, 'Reste à payer', money(toDouble(k['unpaid']), cur), Icons.account_balance_wallet_outlined, YColors.warning, sub: k['pay_days'] == null ? null : 'payé en ${k['pay_days']} j en moyenne'),
              _kpi(c, 'Délai de livraison moyen', k['lead_days'] == null ? '—' : '${_q(k['lead_days'])} jour(s)', Icons.local_shipping_outlined, YColors.night, sub: '${k['orders']} commande(s)'),
              _kpi(c, 'Livraisons à l\'heure', k['on_time'] == null ? '—' : '${k['on_time']} %', Icons.schedule, YColors.success),
              _kpi(c, 'Livraisons complètes', k['complete'] == null ? '—' : '${k['complete']} %', Icons.inventory_outlined, YColors.success, sub: '${k['partial']} partielle(s)'),
              _kpi(c, 'Note', k['score'] == null ? '—' : '${k['score']} / 5', Icons.star_rounded, YColors.warning),
            ]),
            const SizedBox(height: 12),
            SectionCard(title: tr('Achats, 24 derniers mois'), child: SizedBox(height: 220, child: CartesianChart(data: chart, currency: cur, line: false))),
            const SizedBox(height: 12),
            SectionCard(
              title: tr('Par année'),
              child: Column(children: [
                for (final y in List<Map>.from(d['years'] as List))
                  Padding(padding: const EdgeInsets.symmetric(vertical: 4), child: Row(children: [Expanded(child: Text(tr('${y['year']} · ${y['invoices']} facture(s)'))), Text(tr(money(toDouble(y['total']), cur)), style: const TextStyle(fontWeight: FontWeight.w700))])),
                if ((d['years'] as List).isEmpty) Text(tr('Aucune facture.'), style: TextStyle(color: hint)),
              ]),
            ),
            const SizedBox(height: 12),
            SectionCard(
              title: tr('Articles achetés et évolution des prix'),
              child: Column(children: [
                for (final a in List<Map>.from(d['products'] as List))
                  ListTile(
                    dense: true,
                    contentPadding: EdgeInsets.zero,
                    title: Text(tr('${a['name']}'), overflow: TextOverflow.ellipsis),
                    subtitle: Text(tr('${a['purchases']} achat(s) · ${_q(a['quantity'])} unité(s) · du ${dateFr(a['first_date'])} au ${dateFr(a['last_date'])} · min ${money(toDouble(a['min']), cur)}, max ${money(toDouble(a['max']), cur)}')),
                    trailing: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.end, children: [
                      Text(tr(money(toDouble(a['last_price']), cur)), style: const TextStyle(fontWeight: FontWeight.w800)),
                      if (a['change'] != null && toDouble(a['change']) != 0)
                        Text(tr('${toDouble(a['change']) > 0 ? '+' : ''}${a['change']} %'), style: TextStyle(color: toDouble(a['change']) > 0 ? YColors.danger : YColors.success, fontWeight: FontWeight.w700, fontSize: 12)),
                    ]),
                  ),
                if ((d['products'] as List).isEmpty) Text(tr('Aucun article du catalogue acheté.'), style: TextStyle(color: hint)),
              ]),
            ),
            const SizedBox(height: 12),
            Wrap(spacing: 12, runSpacing: 12, children: [
              SizedBox(width: 400, child: SectionCard(title: tr('Importations'), child: Column(children: [for (final x in List<Map>.from(d['imports'] as List)) ListTile(dense: true, contentPadding: EdgeInsets.zero, title: Text(tr('${x['number']} · ${x['title']}')), subtitle: Text(tr('${x['status']}'))), if ((d['imports'] as List).isEmpty) Text('—', style: TextStyle(color: hint))]))),
              SizedBox(width: 400, child: SectionCard(title: tr('Comparatifs'), child: Column(children: [for (final x in List<Map>.from(d['comparisons'] as List)) ListTile(dense: true, contentPadding: EdgeInsets.zero, title: Text(tr('${x['number']} · ${x['title']}')), trailing: x['won'] == true ? StatusChip(tr('Retenu'), color: YColors.success) : null), if ((d['comparisons'] as List).isEmpty) Text('—', style: TextStyle(color: hint))]))),
              SizedBox(width: 400, child: SectionCard(title: tr('Locations'), child: Column(children: [for (final x in List<Map>.from(d['rentals'] as List)) ListTile(dense: true, contentPadding: EdgeInsets.zero, title: Text(tr('${x['number']} · ${x['title']}')), subtitle: Text(tr('du ${dateFr(x['start_date'])} au ${dateFr(x['end_date'])}'))), if ((d['rentals'] as List).isEmpty) Text('—', style: TextStyle(color: hint))]))),
            ]),
          ]);
        },
      ),
    );
  }
}

extension on String {
  String ifEmpty(String other) => isEmpty ? other : this;
}

/// Bouton « Comparer les prix » à placer sur une demande (demande d'achat, demande de prix, appel de fonds, appel d'offres) :
/// ouvre le comparatif lié, ou le crée avec les lignes de la demande.
class CompareButton extends StatelessWidget {
  const CompareButton({super.key, required this.type, required this.id, this.label = 'Comparer les prix'});

  final String type;
  final String id;
  final String label;

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    if (!s.hasModule('appro') || !(s.can('appro.manage') || s.can('appro.view'))) return const SizedBox.shrink();
    return OutlinedButton.icon(onPressed: () => openComparisonFrom(context, type, id), icon: const Icon(Icons.compare_arrows), label: Text(tr(label)));
  }
}

Future<void> openComparisonFrom(BuildContext context, String type, String id) async {
  final s = context.read<Session>();
  try {
    int? cid;
    if (s.can('appro.manage')) {
      final r = await s.api.post('appro/comparisons/from', {'type': type, 'id': id});
      cid = r['data']['id'] as int;
    } else {
      final r = await s.api.get('appro/comparisons', query: {'source_type': type, 'source_id': id});
      final rows = r['data'] as List;
      if (rows.isNotEmpty) cid = (rows.first as Map)['id'] as int;
    }
    if (!context.mounted) return;
    if (cid == null) {
      toast(context, tr('Aucun comparatif pour cette demande : un acheteur peut le lancer.'));
      return;
    }
    await _openComparison(context, cid);
  } catch (e) {
    if (context.mounted) toast(context, errorText(e), error: true);
  }
}
