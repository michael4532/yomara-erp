import 'dart:async';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/local_db.dart';
import '../../core/lots.dart';
import '../../core/stock_calc.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/filter_bar.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

const _statusLabels = {'': 'Tous', 'expired': 'Périmés', 'expiring': 'Bientôt périmés', 'ok': 'Conformes'};

const _moveTypes = {
  'purchase': 'Achat',
  'sale': 'Vente',
  'return': 'Retour client',
  'adjustment': 'Ajustement',
  'loss': 'Perte / rebut',
  'transfer_in': 'Transfert entrant',
  'transfer_out': 'Transfert sortant',
  'inventory': 'Inventaire',
  'production': 'Fabrication',
  'consumption': 'Consommation',
};

/// Lots & numéros de série : stock par lot, péremptions (FEFO), traçabilité amont / aval, mise au rebut.
class LotsScreen extends StatefulWidget {
  const LotsScreen({super.key});

  @override
  State<LotsScreen> createState() => _LotsScreenState();
}

class _LotsScreenState extends State<LotsScreen> {
  String _status = '';
  String _q = '';
  Map<String, String?> _f = {};
  FilterPeriod? _period;
  List<Map<String, dynamic>>? _rows;
  Map<String, dynamic> _settings = const {};
  String? _error;
  bool _offline = false;
  Timer? _debounce;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    super.dispose();
  }

  Future<void> _load() async {
    final api = context.read<Session>().api;
    try {
      final r = await api.get('lots/levels', query: {'status': _status.isEmpty ? 'all' : _status, if (_q.length >= 2) 'q': _q});
      if (!mounted) return;
      setState(() {
        _rows = [for (final e in (r['data'] as List? ?? const [])) asMap(e)];
        _settings = asMap(r['settings']);
        _error = null;
        _offline = false;
      });
    } catch (e) {
      if (!mounted) return;
      // Hors ligne : calcul local depuis les mouvements synchronisés
      setState(() {
        _rows = _localLevels();
        _offline = true;
        _error = _rows!.isEmpty ? errorText(e) : null;
      });
    }
  }

  List<Map<String, dynamic>> _localLevels() {
    if (!LocalDb.hasEntity('stock_lots')) return [];
    final alert = toDouble(_settings['alert_days'] ?? 30);
    final out = <Map<String, dynamic>>[];
    for (final p in LocalDb.all('products').where(isTracked)) {
      for (final l in availableLots('${p['id']}')) {
        final days = l.daysLeft;
        final st = days == null ? 'ok' : (days < 0 ? 'expired' : (days <= toDouble(p['expiry_alert_days'] ?? alert) ? 'expiring' : 'ok'));
        if (_status.isNotEmpty && st != _status) continue;
        if (_q.length >= 2 && !'${l.number} ${p['name']}'.toLowerCase().contains(_q.toLowerCase())) continue;
        out.add({'lot_id': l.id, 'number': l.number, 'expiry_date': l.lot['expiry_date'], 'days_left': days, 'status': st, 'product_id': p['id'], 'product_name': p['name'], 'tracking': p['tracking'], 'quantity': l.quantity, 'value': l.quantity * toDouble(p['purchase_price'])});
      }
    }
    return out;
  }

  Future<void> _scrap(List<Map<String, dynamic>> rows) async {
    final ids = rows.map((r) => r['lot_id']).toSet().toList();
    if (!await confirm(context, tr('Mettre au rebut ?'), tr('${ids.length} lot(s) sortiront du stock en perte (mouvement « rebut » tracé).'), ok: tr('Mettre au rebut'), danger: true)) return;
    if (!mounted) return;
    try {
      final r = await context.read<Session>().api.post('lots/scrap', {'lot_ids': ids});
      if (mounted) toast(context, tr('${r['data']?['moves'] ?? 0} mouvement(s) de perte enregistré(s).'));
      _load();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _settingsDialog() async {
    final days = TextEditingController(text: '${_settings['alert_days'] ?? 30}');
    var block = _settings['block_expired'] != false;
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => StatefulBuilder(
        builder: (c, set) => YAlertDialog(
          title: Text(tr('Réglages des lots')),
          content: SizedBox(
            width: 400,
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              Field(label: tr('Alerte avant péremption (jours)'), child: TextField(controller: days, keyboardType: TextInputType.number)),
              SwitchListTile(
                contentPadding: EdgeInsets.zero,
                title: Text(tr('Bloquer la vente des lots périmés')),
                subtitle: Text(tr('Ils restent sortables en perte (rebut).')),
                value: block,
                onChanged: (v) => set(() => block = v),
              ),
            ]),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))),
            FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Enregistrer'))),
          ],
        ),
      ),
    );
    if (ok != true || !mounted) return;
    try {
      final r = await context.read<Session>().api.put('lots/settings', {'alert_days': int.tryParse(days.text) ?? 30, 'block_expired': block});
      if (mounted) setState(() => _settings = asMap(r['data']));
      _load();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final canManage = session.can('stock.manage');
    final narrow = MediaQuery.of(context).size.width < 700;
    final rows = _rows ?? const <Map<String, dynamic>>[];
    final shown = rows.where((r) {
      final pr = _f['product'], wh = _f['warehouse'], tk = _f['tracking'];
      if (pr != null && '${r['product_id']}' != pr) return false;
      if (wh != null && '${r['warehouse_name'] ?? ''}' != wh) return false;
      if (tk != null && '${r['tracking'] ?? 'lot'}' != tk) return false;
      if (_period != null && !_period!.contains(r['expiry_date'])) return false;
      return true;
    }).toList();
    final expired = rows.where((r) => r['status'] == 'expired').toList();
    final expiring = rows.where((r) => r['status'] == 'expiring').length;
    final value = rows.fold<double>(0, (s, r) => s + toDouble(r['value']));
    final hint = Theme.of(context).hintColor;

    return Column(children: [
      PageHeader(
        title: tr('Lots & numéros de série'),
        subtitle: tr('Péremptions, sorties FEFO, traçabilité fournisseur › client'),
        actions: [
          IconButton(tooltip: tr('Actualiser'), onPressed: _load, icon: const Icon(Icons.refresh)),
          if (canManage) IconButton(tooltip: tr('Réglages'), onPressed: _settingsDialog, icon: const Icon(Icons.tune)),
          if (canManage && expired.isNotEmpty && !_offline)
            FilledButton.icon(
              style: FilledButton.styleFrom(backgroundColor: YColors.danger),
              onPressed: () => _scrap(expired),
              icon: const Icon(Icons.delete_sweep_outlined),
              label: Text(tr('Rebut des périmés (${expired.length})')),
            ),
        ],
      ),
      Padding(
        padding: EdgeInsets.symmetric(horizontal: narrow ? 16 : 24),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          KpiWrap(children: [
            _Kpi('Lots en stock', '${rows.map((r) => r['lot_id']).toSet().length}', Icons.inventory_2_outlined, YColors.blue),
            _Kpi('Périmés', '${expired.length}', Icons.block, YColors.danger),
            _Kpi('À écouler (alerte)', '$expiring', Icons.schedule, YColors.warning),
            _Kpi('Valeur (prix d\'achat)', money(value, session.currency), Icons.payments_outlined, YColors.success),
          ]),
          const SizedBox(height: 12),
        ]),
      ),
      FilterBar(
        search: _q,
        searchHint: 'N° de lot ou de série, article…',
        onSearch: (v) {
          setState(() => _q = v.trim());
          _debounce?.cancel();
          if (_q.isEmpty || _q.length >= 2) _debounce = Timer(const Duration(milliseconds: 400), _load);
        },
        statuses: _statusLabels,
        status: _status,
        onStatus: (v) {
          setState(() => _status = v);
          _load();
        },
        filters: [
          FilterSpec('product', 'Article', filterOptions(rows, 'product_id', label: (r) => '${r['product_name']}'), icon: Icons.inventory_2_outlined),
          FilterSpec('warehouse', 'Entrepôt', filterOptions(rows, 'warehouse_name'), icon: Icons.warehouse_outlined),
          const FilterSpec('tracking', 'Suivi', {'lot': 'Par lot', 'serial': 'Par n° de série'}, icon: Icons.qr_code_2),
        ],
        values: _f,
        onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
        showPeriod: true,
        period: _period,
        onPeriod: (v) => setState(() => _period = v),
        count: _rows == null ? null : shown.length,
        countLabel: 'lot',
        onReset: () {
          setState(() => (_q = '', _status = '', _f = {}, _period = null));
          _load();
        },
      ),
      Padding(
        padding: EdgeInsets.symmetric(horizontal: narrow ? 16 : 24),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          if (_offline) Padding(padding: const EdgeInsets.only(bottom: 8), child: Text(tr('Hors ligne : stock par lot calculé sur cet appareil.'), style: TextStyle(fontSize: 12, color: hint))),
        ]),
      ),
      Expanded(
        child: _rows == null
            ? const Center(child: CircularProgressIndicator())
            : rows.isEmpty
                ? EmptyState(icon: Icons.inventory_2_outlined, title: _error != null ? 'Données indisponibles' : 'Aucun lot', message: _error ?? 'Activez la traçabilité par lot ou par n° de série sur vos articles (fiche produit).')
                : shown.isEmpty
                ? EmptyState(icon: Icons.search_off, title: tr('Aucun résultat'))
                : ResponsiveList<Map<String, dynamic>>(
                    items: shown,
                    columns: const ['Lot / série', 'Article', 'Entrepôt', 'Péremption', 'Quantité', 'Statut'],
                    onTap: (r) => showLotTrace(context, '${r['lot_id']}'),
                    cells: (r) => [
                      Row(children: [
                        Icon(r['tracking'] == 'serial' ? Icons.qr_code_2 : Icons.inventory_2_outlined, size: 16, color: hint),
                        const SizedBox(width: 6),
                        Flexible(child: Text(tr('${r['number']}'), style: const TextStyle(fontWeight: FontWeight.w600), overflow: TextOverflow.ellipsis)),
                      ]),
                      Text(tr('${r['product_name']}'), overflow: TextOverflow.ellipsis),
                      Text(tr('${r['warehouse_name'] ?? '—'}')),
                      Text(tr(r['expiry_date'] == null ? '—' : '${dateFr(r['expiry_date'])}${r['days_left'] != null ? ' (${_days(r['days_left'])})' : ''}')),
                      Text(tr(qty(toDouble(r['quantity'])))),
                      _statusChip(r),
                    ],
                    mobileTile: (r) => Row(children: [
                      Expanded(
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Text(tr('${r['number']} · ${r['product_name']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                          Text(tr('${qty(toDouble(r['quantity']))} en stock${r['expiry_date'] != null ? ' · péremption ${dateFr(r['expiry_date'])}' : ''}'), style: TextStyle(color: hint)),
                        ]),
                      ),
                      _statusChip(r),
                    ]),
                  ),
      ),
    ]);
  }

  static String _days(dynamic d) {
    final n = toDouble(d).toInt();
    if (n < 0) return 'depuis ${-n} j';
    if (n == 0) return 'aujourd\'hui';
    return 'dans $n j';
  }

  Widget _statusChip(Map<String, dynamic> r) => switch (r['status']) {
        'expired' => StatusChip(tr('Périmé'), color: YColors.danger),
        'expiring' => StatusChip(tr('À écouler'), color: YColors.warning),
        _ => StatusChip(tr('Conforme'), color: YColors.success),
      };
}

class _Kpi extends StatelessWidget {
  const _Kpi(this.label, this.value, this.icon, this.color);

  final String label;
  final String value;
  final IconData icon;
  final Color color;

  @override
  Widget build(BuildContext context) => KpiBox(width: 230, child: StatTile(label: label, value: value, icon: icon, color: color));
}

/// Traçabilité d'un lot : d'où il vient, où il est allé, chez quels clients.
Future<void> showLotTrace(BuildContext context, String lotId) async {
  final api = context.read<Session>().api;
  Map<String, dynamic>? data;
  String? error;
  try {
    data = asMap((await api.get('lots/lots/$lotId/trace'))['data']);
  } catch (e) {
    error = errorText(e);
  }
  if (!context.mounted) return;
  final currencyHint = Theme.of(context).hintColor;
  await showDialog(
    context: context,
    builder: (c) {
      final lot = asMap(data?['lot']);
      final moves = [for (final m in (data?['moves'] as List? ?? const [])) asMap(m)];
      final partners = [for (final m in (data?['partners'] as List? ?? const [])) asMap(m)];
      return YAlertDialog(
        title: Text(tr(data == null ? 'Traçabilité' : '${lot['tracking'] == 'serial' ? 'N° de série' : 'Lot'} ${lot['number']} — ${lot['product_name']}')),
        content: SizedBox(
          width: 620,
          child: data == null
              ? Text(tr(error ?? 'Indisponible'), style: TextStyle(color: currencyHint))
              : SingleChildScrollView(
                  child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                    Wrap(spacing: 16, runSpacing: 6, children: [
                      Text(tr('En stock : ${qty(toDouble(data['stock']))}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                      if (lot['expiry_date'] != null) Text(tr('Péremption : ${dateFr(lot['expiry_date'])}')),
                      if (lot['supplier_lot'] != null) Text(tr('Lot fournisseur : ${lot['supplier_lot']}')),
                    ]),
                    if (partners.isNotEmpty) ...[
                      const SizedBox(height: 12),
                      Text(tr('Fournisseurs et clients concernés'), style: Theme.of(c).textTheme.titleSmall),
                      const SizedBox(height: 4),
                      Wrap(spacing: 8, runSpacing: 8, children: [
                        for (final p in partners)
                          Chip(
                            avatar: Icon(p['direction'] == 'purchase' ? Icons.local_shipping_outlined : Icons.person_outline, size: 16),
                            label: Text(tr('${p['name']} · ${qty(toDouble(p['quantity']))}')),
                          ),
                      ]),
                    ],
                    const SizedBox(height: 12),
                    Text(tr('Mouvements'), style: Theme.of(c).textTheme.titleSmall),
                    for (final m in moves)
                      ListTile(
                        dense: true,
                        contentPadding: EdgeInsets.zero,
                        leading: Icon(toDouble(m['quantity']) >= 0 ? Icons.south_west : Icons.north_east, color: toDouble(m['quantity']) >= 0 ? YColors.success : YColors.danger, size: 18),
                        title: Text(tr('${_moveTypes[m['type']] ?? m['type']}${m['document'] != null ? ' · ${m['document']}' : ''}')),
                        subtitle: Text(tr([dateFr(m['date']), if (m['warehouse'] != null) '${m['warehouse']}', if (m['notes'] != null) '${m['notes']}'].join(' · '))),
                        trailing: Text(tr('${toDouble(m['quantity']) > 0 ? '+' : ''}${qty(toDouble(m['quantity']))}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                      ),
                  ]),
                ),
        ),
        actions: [TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Fermer')))],
      );
    },
  );
}

/// Stock total d'un article suivi, réparti par lot (pour info rapide).
String lotBreakdown(String productId) {
  final lots = availableLots(productId);
  if (lots.isEmpty) return '';
  final total = StockCalc.totals()[productId] ?? 0;
  final inLots = lots.fold<double>(0, (s, l) => s + l.quantity);
  return '${lots.length} lot(s)${(total - inLots).abs() > 0.001 ? ' · ${qty(total - inLots)} hors lot' : ''}';
}
