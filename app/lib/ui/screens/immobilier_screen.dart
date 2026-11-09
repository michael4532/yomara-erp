import 'package:yomara_erp/ui/widgets/adaptive_sheet.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:printing/printing.dart';
import 'package:provider/provider.dart';

import '../../core/local_db.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/filter_bar.dart';
import '../widgets/report_charts.dart';
import 'sites_screen.dart' show openUrl;
import '../../i18n/i18n.dart';
import '../../core/json.dart';

const _months = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
const _monthsLong = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

String _ym(DateTime d) => '${d.year}-${d.month.toString().padLeft(2, '0')}';
String _monthLabel(DateTime d) => '${_monthsLong[d.month - 1]} ${d.year}';

Color _rentColor(String s) => switch (s) {
      'paid' => YColors.success,
      'partial' => YColors.warning,
      'unpaid' => YColors.danger,
      _ => YColors.muted,
    };

Color _unitColor(String s) => switch (s) {
      'occupied' => YColors.success,
      'works' => YColors.warning,
      _ => YColors.blue,
    };

Future<void> _openPdf(BuildContext c, String path, String name, {Map<String, String>? query}) async {
  try {
    final bytes = await c.read<Session>().api.download(path, query: query);
    await Printing.layoutPdf(name: name, onLayout: (_) async => Uint8List.fromList(bytes));
  } catch (e) {
    if (c.mounted) toast(c, errorText(e), error: true);
  }
}

/// Gestion locative : biens et lots, baux, appels de loyer, impayés, dépenses, relevés de gérance.
class ImmobilierScreen extends StatefulWidget {
  const ImmobilierScreen({super.key});

  @override
  State<ImmobilierScreen> createState() => _ImmobilierScreenState();
}

class _ImmobilierScreenState extends State<ImmobilierScreen> with SingleTickerProviderStateMixin {
  late final Session _s = context.read<Session>();
  late final bool _manage = _s.can('immobilier.manage');
  late final bool _owners = _s.can('immobilier.owners');
  late final TabController _tabs = TabController(length: 5 + (_owners ? 1 : 0), vsync: this);
  int _rev = 0;

  void _changed() => setState(() => _rev++);

  @override
  Widget build(BuildContext context) {
    return Column(children: [
      PageHeader(
        title: tr('Gestion locative'),
        subtitle: tr('Biens, lots et baux · appels de loyer, encaissements et quittances · impayés · relevés de gérance'),
        actions: [
          if (_manage)
            FilledButton.icon(
              onPressed: () async {
                try {
                  final r = await _s.api.post('immobilier/rents/generate');
                  final d = r['data'] as Map;
                  if (context.mounted) toast(context, d['rents'] == 0 ? 'Tous les loyers sont déjà appelés.' : '${d['rents']} appel(s) de loyer émis${toDouble(d['errors']) > 0 ? ', ${d['errors']} en erreur' : ''}.');
                  _changed();
                } catch (e) {
                  if (context.mounted) toast(context, errorText(e), error: true);
                }
              },
              icon: const Icon(Icons.receipt_long_outlined, size: 18),
              label: Text(tr('Émettre les appels de loyer')),
            ),
        ],
      ),
      TabBar(controller: _tabs, isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [
        Tab(text: tr('Tableau de bord')),
        Tab(text: tr('Biens & lots')),
        Tab(text: tr('Baux')),
        Tab(text: tr('Loyers')),
        Tab(text: tr('Dépenses')),
        if (_owners) Tab(text: tr('Propriétaires')),
      ]),
      Expanded(
        child: TabBarView(controller: _tabs, children: [
          _Dashboard(key: ValueKey('d$_rev'), manage: _manage, onChanged: _changed),
          _Properties(key: ValueKey('p$_rev'), manage: _manage, onChanged: _changed),
          _Leases(key: ValueKey('l$_rev'), manage: _manage, onChanged: _changed),
          _Rents(key: ValueKey('r$_rev'), manage: _manage, onChanged: _changed),
          _Expenses(key: ValueKey('e$_rev'), manage: _manage),
          if (_owners) _Owners(key: ValueKey('o$_rev')),
        ]),
      ),
    ]);
  }
}

/// Chargement d'une ressource de l'API avec états (chargement, erreur hors ligne).
mixin _Loader<T extends StatefulWidget> on State<T> {
  Map<String, dynamic>? res;
  Object? error;

  String get path;
  Map<String, dynamic>? get query => null;

  Session get session => context.read<Session>();

  @override
  void initState() {
    super.initState();
    load();
  }

  Future<void> load() async {
    try {
      final r = await session.api.get(path, query: query);
      if (mounted) setState(() => (res = asMap(r), error = null));
    } catch (e) {
      if (mounted) setState(() => error = e);
    }
  }

  Widget? state() {
    if (error != null && res == null) return OnlineOnlyNotice(error: error!, onRetry: load);
    if (res == null) return const LoadingSkeleton();
    return null;
  }
}

Widget _kpi(BuildContext context, String l, String v, {String? sub, Color? color, IconData? icon}) => KpiBox(
      width: 262,
      child: StatTile(label: l, value: v, detail: sub, icon: icon, color: color ?? YColors.blue, valueColor: color),
    );

// ---------------------------------------------------------------------------
// Tableau de bord
// ---------------------------------------------------------------------------

class _Dashboard extends StatefulWidget {
  const _Dashboard({super.key, required this.manage, required this.onChanged});

  final bool manage;
  final VoidCallback onChanged;

  @override
  State<_Dashboard> createState() => _DashboardState();
}

class _DashboardState extends State<_Dashboard> with _Loader {
  DateTime _month = DateTime(DateTime.now().year, DateTime.now().month);

  @override
  String get path => 'immobilier/dashboard';

  @override
  Map<String, dynamic>? get query => {'month': _ym(_month)};

  Future<void> _remind(Map<String, dynamic> o) async {
    try {
      await session.api.post('immobilier/leases/${o['lease_id']}/remind');
      if (mounted) toast(context, tr('Relance envoyée à ${(o['tenant'] as Map?)?['name']}.'));
      load();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final st = state();
    if (st != null) return st;
    final d = asMap(res!['data']);
    final cur = context.watch<Session>().currency;
    final hint = Theme.of(context).hintColor;
    final months = [for (final m in d['months'] as List) asMap(m)];
    final overdue = [for (final o in d['overdue'] as List) asMap(o)];
    final ending = [for (final o in d['ending'] as List) asMap(o)];
    final revisions = [for (final o in d['revisions'] as List) asMap(o)];
    return RefreshIndicator(
      onRefresh: load,
      child: ListView(padding: const EdgeInsets.all(16), children: [
        Row(children: [
          IconButton(onPressed: () => setState(() => (_month = DateTime(_month.year, _month.month - 1), load())), icon: const Icon(Icons.chevron_left)),
          Text(tr(_monthLabel(_month)), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
          IconButton(onPressed: () => setState(() => (_month = DateTime(_month.year, _month.month + 1), load())), icon: const Icon(Icons.chevron_right)),
        ]),
        KpiWrap(children: [
          _kpi(context, 'Taux d\'occupation', d['occupancy'] == null ? '—' : '${number(toDouble(d['occupancy']), decimals: 1)} %',
              sub: '${d['occupied']} loué(s) sur ${d['units']} lot(s) · ${d['vacant']} libre(s)', icon: Icons.apartment, color: YColors.blue),
          _kpi(context, 'Loyers appelés du mois', money(toDouble(d['billed']), cur), sub: 'état locatif ${money(toDouble(d['rent_roll']), cur)} / mois', icon: Icons.receipt_long_outlined, color: YColors.night),
          _kpi(context, 'Recouvrement du mois', d['collection_rate'] == null ? '—' : '${number(toDouble(d['collection_rate']), decimals: 1)} %',
              sub: '${money(toDouble(d['paid_of_month']), cur)} payés', icon: Icons.task_alt, color: YColors.success),
          _kpi(context, 'Encaissé dans le mois', money(toDouble(d['collected']), cur), sub: 'tous loyers confondus', icon: Icons.payments_outlined, color: YColors.success),
          _kpi(context, 'Impayés', money(toDouble(d['overdue_total']), cur), sub: '${d['overdue_tenants']} locataire(s) en retard', icon: Icons.warning_amber_rounded, color: YColors.danger),
          _kpi(context, 'Cautions détenues', money(toDouble(d['deposits']), cur), sub: 'baux en cours', icon: Icons.lock_outline, color: const Color(0xFF8E44AD)),
          _kpi(context, 'Manque à gagner (lots libres)', money(toDouble(d['vacant_loss']), cur), sub: '${d['works']} lot(s) en travaux', icon: Icons.trending_down, color: YColors.warning),
        ]),
        const SizedBox(height: 12),
        if (months.any((m) => toDouble(m['billed']) > 0 || toDouble(m['collected']) > 0))
          SectionCard(
            title: tr('Loyers appelés et encaissés (12 mois)'),
            child: SizedBox(
              height: 240,
              child: CartesianChart(
                data: ChartData(
                  [for (final m in months) '${_months[int.parse('${m['month']}'.substring(5)) - 1]} ${'${m['month']}'.substring(2, 4)}'],
                  [ChartSeries('Appelés', [for (final m in months) toDouble(m['billed'])]), ChartSeries('Encaissés', [for (final m in months) toDouble(m['collected'])])],
                  'money',
                ),
                currency: cur,
                line: false,
              ),
            ),
          ),
        const SizedBox(height: 12),
        SectionCard(
          title: tr('Locataires en retard'),
          child: overdue.isEmpty
              ? Text(tr('Aucun impayé : tous les loyers échus sont réglés.'), style: TextStyle(color: hint))
              : Column(children: [
                  for (final o in overdue)
                    ListTile(
                      contentPadding: EdgeInsets.zero,
                      leading: CircleAvatar(backgroundColor: YColors.danger.withValues(alpha: .12), child: const Icon(Icons.person_outline, color: YColors.danger)),
                      title: Text(tr('${(o['tenant'] as Map?)?['name'] ?? '—'}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                      subtitle: Text(tr('${o['property']} — ${o['unit']} · ${o['count']} loyer(s) · retard ${o['days_late']} j')),
                      trailing: Wrap(crossAxisAlignment: WrapCrossAlignment.center, spacing: 4, children: [
                        Text(tr(money(toDouble(o['balance']), cur)), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15, color: YColors.danger)),
                        if (widget.manage) IconButton(tooltip: tr('Relancer (WhatsApp, SMS ou e-mail)'), onPressed: () => _remind(o), icon: const Icon(Icons.send_outlined, size: 20)),
                      ]),
                      onTap: () => _leaseSheet(context, o['lease_id'] as int, widget.manage, () { load(); widget.onChanged(); }),
                    ),
                ]),
        ),
        if (ending.isNotEmpty || revisions.isNotEmpty) ...[
          const SizedBox(height: 12),
          Wrap(spacing: 12, runSpacing: 12, children: [
            if (ending.isNotEmpty)
              SizedBox(
                width: 460,
                child: SectionCard(
                  title: tr('Baux qui se terminent (60 jours)'),
                  child: Column(children: [
                    for (final l in ending)
                      ListTile(dense: true, contentPadding: EdgeInsets.zero, title: Text(tr('${l['property_name']} — ${l['unit_reference']}')), subtitle: Text(tr('${(l['tenant'] as Map?)?['name']} · ${l['number']}')), trailing: Text(tr(dateFr(l['end_date'])))),
                  ]),
                ),
              ),
            if (revisions.isNotEmpty)
              SizedBox(
                width: 460,
                child: SectionCard(
                  title: tr('Révisions de loyer à venir'),
                  child: Column(children: [
                    for (final l in revisions)
                      ListTile(
                        dense: true,
                        contentPadding: EdgeInsets.zero,
                        title: Text(tr('${l['property_name']} — ${l['unit_reference']}')),
                        subtitle: Text(tr('${money(toDouble(l['rent']), cur)} → ${money((toDouble(l['rent']) * (1 + toDouble(l['revision_rate']) / 100)).roundToDouble(), cur)} (+${number(toDouble(l['revision_rate']), decimals: 1)} %)')),
                        trailing: Text(tr(dateFr(l['next_revision_date']))),
                      ),
                  ]),
                ),
              ),
          ]),
        ],
      ]),
    );
  }
}

// ---------------------------------------------------------------------------
// Biens & lots
// ---------------------------------------------------------------------------

class _Properties extends StatefulWidget {
  const _Properties({super.key, required this.manage, required this.onChanged});

  final bool manage;
  final VoidCallback onChanged;

  @override
  State<_Properties> createState() => _PropertiesState();
}

class _PropertiesState extends State<_Properties> with _Loader {
  String _q = '';
  String _status = '';
  final Map<String, String?> _f = {};
  int _gen = 0;

  @override
  String get path => 'immobilier/properties';

  Map<String, dynamic> get _meta => asMap(res!['meta']);

  Future<void> _property([Map<String, dynamic>? p]) async {
    final name = TextEditingController(text: '${p?['name'] ?? ''}');
    final district = TextEditingController(text: '${p?['district'] ?? ''}');
    final city = TextEditingController(text: '${p?['city'] ?? 'Abidjan'}');
    final address = TextEditingController(text: '${p?['address'] ?? ''}');
    final fee = TextEditingController(text: p == null ? '' : number(toDouble(p['fee_rate']), decimals: 1));
    final ownerName = TextEditingController();
    final ownerPhone = TextEditingController();
    var kind = '${p?['kind'] ?? 'immeuble'}';
    var mode = p == null ? 'own' : (p['owner_id'] == null ? 'own' : 'keep');
    final kinds = asMap(_meta['property_kinds']);
    final ok = await showFormDialog<bool>(context, title: p == null ? 'Nouveau bien' : 'Modifier ${p['name']}', builder: (c) => StatefulBuilder(
        builder: (c, set) => SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
            Column(mainAxisSize: MainAxisSize.min, children: [
              TextField(controller: name, decoration: InputDecoration(labelText: tr('Nom du bien *'), hintText: tr('Résidence Les Palmiers, Villa Riviera 3…'))),
              const SizedBox(height: 8),
              DropdownButtonFormField<String>(
                initialValue: kind,
                decoration: InputDecoration(labelText: tr('Type')),
                items: [for (final e in kinds.entries) DropdownMenuItem(value: e.key, child: Text(tr('${e.value}')))],
                onChanged: (v) => kind = v ?? kind,
              ),
              const SizedBox(height: 8),
              Row(children: [
                Expanded(child: TextField(controller: district, decoration: InputDecoration(labelText: tr('Quartier / commune')))),
                const SizedBox(width: 10),
                Expanded(child: TextField(controller: city, decoration: InputDecoration(labelText: tr('Ville')))),
              ]),
              const SizedBox(height: 8),
              TextField(controller: address, decoration: InputDecoration(labelText: tr('Adresse, repère'))),
              const SizedBox(height: 12),
              SegmentedButton<String>(
                segments: [
                  ButtonSegment(value: 'own', label: Text(tr('Bien de la société'))),
                  if (p?['owner_id'] != null) ButtonSegment(value: 'keep', label: Text(tr('${(p!['owner'] as Map?)?['name'] ?? 'Propriétaire'}'))),
                  ButtonSegment(value: 'new', label: Text(tr('Nouveau propriétaire'))),
                ],
                selected: {mode},
                onSelectionChanged: (v) => set(() => mode = v.first),
              ),
              if (mode == 'new') ...[
                const SizedBox(height: 8),
                Row(children: [
                  Expanded(child: TextField(controller: ownerName, decoration: InputDecoration(labelText: tr('Propriétaire *')))),
                  const SizedBox(width: 10),
                  Expanded(child: TextField(controller: ownerPhone, keyboardType: TextInputType.phone, decoration: InputDecoration(labelText: tr('Téléphone')))),
                ]),
              ],
              if (mode != 'own') ...[
                const SizedBox(height: 8),
                TextField(controller: fee, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Honoraires de gérance (% des loyers encaissés)'), suffixText: '%')),
              ],
            ]),
            const SizedBox(height: 20),
            Align(
              alignment: Alignment.centerRight,
              child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
                TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))),
                FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Enregistrer'))),
              ]),
            ),
          ]),
        ),
      ));
    if (ok != true) return;
    final body = {
      'name': name.text.trim(), 'kind': kind, 'district': district.text.trim(), 'city': city.text.trim(), 'address': address.text.trim(),
      if (mode == 'own') 'owner_id': null,
      if (mode == 'new') ...{'owner_name': ownerName.text.trim(), 'owner_phone': ownerPhone.text.trim()},
      'fee_rate': mode == 'own' ? 0 : toDouble(fee.text),
    };
    try {
      p == null ? await session.api.post('immobilier/properties', body) : await session.api.put('immobilier/properties/${p['id']}', body);
      await load();
      if (mounted) toast(context, tr('Bien enregistré.'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _unit(Map<String, dynamic> p, [Map<String, dynamic>? u]) async {
    final ref = TextEditingController(text: '${u?['reference'] ?? ''}');
    final rooms = TextEditingController(text: u?['rooms'] == null ? '' : '${u!['rooms']}');
    final surface = TextEditingController(text: u?['surface'] == null ? '' : number(toDouble(u!['surface'])));
    final floor = TextEditingController(text: '${u?['floor'] ?? ''}');
    final rent = TextEditingController(text: u == null ? '' : '${toDouble(u['rent']).round()}');
    final charges = TextEditingController(text: u == null ? '' : '${toDouble(u['charges']).round()}');
    var kind = '${u?['kind'] ?? 'appartement'}';
    var status = '${u?['status'] ?? 'vacant'}';
    final kinds = asMap(_meta['unit_kinds']);
    final ok = await showFormDialog<bool>(context, title: u == null ? 'Nouveau lot — ${p['name']}' : 'Lot ${u['reference']}', builder: (c) => StatefulBuilder(
        builder: (c, set) => SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
            Column(mainAxisSize: MainAxisSize.min, children: [
              Row(children: [
                Expanded(child: TextField(controller: ref, decoration: InputDecoration(labelText: tr('Référence *'), hintText: tr('A1, Porte 3, Magasin 2…')))),
                const SizedBox(width: 10),
                Expanded(
                  child: DropdownButtonFormField<String>(
                    initialValue: kind,
                    decoration: InputDecoration(labelText: tr('Type')),
                    items: [for (final e in kinds.entries) DropdownMenuItem(value: e.key, child: Text(tr('${e.value}')))],
                    onChanged: (v) => kind = v ?? kind,
                  ),
                ),
              ]),
              const SizedBox(height: 8),
              Row(children: [
                Expanded(child: TextField(controller: rooms, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Pièces')))),
                const SizedBox(width: 10),
                Expanded(child: TextField(controller: surface, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Surface'), suffixText: 'm²'))),
                const SizedBox(width: 10),
                Expanded(child: TextField(controller: floor, decoration: InputDecoration(labelText: tr('Étage')))),
              ]),
              const SizedBox(height: 8),
              Row(children: [
                Expanded(child: TextField(controller: rent, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Loyer mensuel')))),
                const SizedBox(width: 10),
                Expanded(child: TextField(controller: charges, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Charges mensuelles')))),
              ]),
              if (u == null || u['status'] != 'occupied') ...[
                const SizedBox(height: 8),
                SwitchListTile(contentPadding: EdgeInsets.zero, value: status == 'works', onChanged: (v) => set(() => status = v ? 'works' : 'vacant'), title: Text(tr('En travaux (non proposé à la location)'))),
              ],
            ]),
            const SizedBox(height: 20),
            Align(
              alignment: Alignment.centerRight,
              child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
                TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))),
                FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Enregistrer'))),
              ]),
            ),
          ]),
        ),
      ));
    if (ok != true) return;
    final body = {
      'property_id': p['id'], 'reference': ref.text.trim(), 'kind': kind, if (rooms.text.trim().isNotEmpty) 'rooms': int.tryParse(rooms.text.trim()),
      if (surface.text.trim().isNotEmpty) 'surface': toDouble(surface.text), 'floor': floor.text.trim(), 'rent': toDouble(rent.text), 'charges': toDouble(charges.text),
      if (u == null || u['status'] != 'occupied') 'status': status,
    };
    try {
      u == null ? await session.api.post('immobilier/units', body) : await session.api.put('immobilier/units/${u['id']}', body);
      await load();
      if (mounted) toast(context, tr('Lot enregistré.'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final st = state();
    if (st != null) return st;
    final allProps = [for (final p in res!['data'] as List) asMap(p)];
    final cur = context.watch<Session>().currency;
    final hint = Theme.of(context).hintColor;
    List<Map<String, dynamic>> units(Map<String, dynamic> p) => [for (final u in (p['units'] as List? ?? const [])) asMap(u)];
    String owner(Map<String, dynamic> p) => p['owner'] != null ? '${(p['owner'] as Map)['name']}' : 'Bien de la société';
    final base = allProps
        .where((p) => _q.isEmpty || '${p['name']} ${p['district'] ?? ''} ${p['city'] ?? ''} ${owner(p)} ${units(p).map((u) => '${u['reference']} ${((u['lease'] as Map?)?['tenant'] as Map?)?['name'] ?? ''}').join(' ')}'.toLowerCase().contains(_q))
        .where((p) => _f['kind'] == null || '${p['kind_label']}' == _f['kind'])
        .where((p) => _f['city'] == null || '${p['city']}' == _f['city'])
        .where((p) => _f['owner'] == null || owner(p) == _f['owner'])
        .toList();
    final unitStatuses = <String, String>{'': 'Tous', for (final p in allProps) for (final u in units(p)) '${u['status']}': '${u['status_label'] ?? u['status']}'};
    // Filtre d'état des lots : le bien reste affiché avec ses seuls lots correspondants
    final props = _status.isEmpty ? base : [for (final p in base) if (units(p).any((u) => u['status'] == _status)) {...p, 'units': units(p).where((u) => u['status'] == _status).toList()}];
    final owners = <String, String>{for (final p in allProps) owner(p): owner(p)};
    return RefreshIndicator(
      onRefresh: load,
      child: ListView(padding: const EdgeInsets.all(16), children: [
        FilterBar(
          key: ValueKey(_gen),
          padding: const EdgeInsets.only(bottom: 10),
          onSearch: (v) => setState(() => _q = v.toLowerCase()),
          search: _q,
          searchHint: 'Bien, quartier, lot, locataire…',
          statuses: unitStatuses.length > 2 ? unitStatuses : null,
          status: _status,
          onStatus: (v) => setState(() => _status = v),
          statusCounts: {for (final k in unitStatuses.keys) k: base.fold<int>(0, (n, p) => n + units(p).where((u) => k.isEmpty || u['status'] == k).length)},
          filters: [
            FilterSpec('kind', 'Type de bien', filterOptions(allProps, 'kind_label'), icon: Icons.category_outlined),
            FilterSpec('city', 'Ville', filterOptions(allProps, 'city'), icon: Icons.place_outlined),
            FilterSpec('owner', 'Propriétaire', Map.fromEntries(owners.entries.toList()..sort((a, b) => a.value.toLowerCase().compareTo(b.value.toLowerCase()))), icon: Icons.person_outline),
          ],
          values: _f,
          onFilter: (k, v) => setState(() => _f[k] = v),
          count: props.length,
          countLabel: 'bien',
          onReset: () => setState(() {
            _q = '';
            _status = '';
            _f.clear();
            _gen++;
          }),
          trailing: [
            if (widget.manage) FilledButton.tonalIcon(onPressed: () => _property(), icon: const Icon(Icons.add_home_work_outlined), label: Text(tr('Nouveau bien'))),
          ],
        ),
        if (allProps.isEmpty)
          EmptyState(icon: Icons.apartment, title: tr('Aucun bien'), message: tr('Ajoutez vos immeubles, villas, cours communes ou magasins, puis leurs lots (appartements, studios, magasins…).'))
        else if (props.isEmpty)
          EmptyState(icon: Icons.filter_alt_off_outlined, title: tr('Aucun résultat'), message: tr('Aucun bien ne correspond à ces filtres.')),
        for (final p in props)
          Card(
            child: Padding(
              padding: const EdgeInsets.all(14),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Row(children: [
                  const CircleAvatar(backgroundColor: Color(0x1A1F6FEB), child: Icon(Icons.apartment, color: YColors.blue)),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(tr('${p['name']}'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
                      Text(tr([
                        '${p['kind_label']}',
                        if ('${p['district'] ?? ''}'.isNotEmpty) '${p['district']}',
                        if ('${p['city'] ?? ''}'.isNotEmpty) '${p['city']}',
                        p['owner'] != null ? 'propriétaire : ${(p['owner'] as Map)['name']} · honoraires ${number(toDouble(p['fee_rate']), decimals: 1)} %' : 'bien de la société',
                      ].join(' · ')), style: TextStyle(color: hint, fontSize: 12.5)),
                    ]),
                  ),
                  if (widget.manage) ...[
                    IconButton(tooltip: tr('Ajouter un lot'), onPressed: () => _unit(p), icon: const Icon(Icons.add_box_outlined)),
                    IconButton(tooltip: tr('Modifier'), onPressed: () => _property(p), icon: const Icon(Icons.edit_outlined)),
                  ],
                ]),
                const SizedBox(height: 10),
                if ((p['units'] as List).isEmpty) Text(tr('Aucun lot : ajoutez les appartements, magasins ou bureaux de ce bien.'), style: TextStyle(color: hint)),
                Wrap(spacing: 10, runSpacing: 10, children: [
                  for (final u in (p['units'] as List).map((x) => asMap(x)))
                    SizedBox(
                      width: 250,
                      child: Card(
                        margin: EdgeInsets.zero,
                        color: _unitColor('${u['status']}').withValues(alpha: .06),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12), side: BorderSide(color: _unitColor('${u['status']}').withValues(alpha: .35))),
                        child: InkWell(
                          borderRadius: BorderRadius.circular(12),
                          onTap: () {
                            final lease = u['lease'] as Map?;
                            if (lease != null) {
                              _leaseSheet(context, lease['id'] as int, widget.manage, () { load(); widget.onChanged(); });
                            } else if (widget.manage && u['status'] == 'vacant') {
                              _newLease(context, p, u, () { load(); widget.onChanged(); });
                            } else if (widget.manage) {
                              _unit(p, u);
                            }
                          },
                          child: Padding(
                            padding: const EdgeInsets.all(10),
                            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                              Row(children: [
                                Expanded(child: Text(tr('${u['reference']}'), style: const TextStyle(fontWeight: FontWeight.w800))),
                                StatusChip('${u['status_label']}', color: _unitColor('${u['status']}')),
                                if (widget.manage) InkWell(onTap: () => _unit(p, u), child: const Padding(padding: EdgeInsets.only(left: 4), child: Icon(Icons.more_vert, size: 18))),
                              ]),
                              Text(tr('${u['kind_label']}${u['rooms'] != null ? ' · ${u['rooms']} p.' : ''}${u['surface'] != null ? ' · ${number(toDouble(u['surface']))} m²' : ''}'), style: TextStyle(color: hint, fontSize: 12)),
                              const SizedBox(height: 4),
                              if (u['lease'] != null) ...[
                                Text(tr('${((u['lease'] as Map)['tenant'] as Map?)?['name'] ?? ''}'), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w600)),
                                Text(tr('${money(toDouble((u['lease'] as Map)['rent']) + toDouble((u['lease'] as Map)['charges']), cur)} / mois'), style: TextStyle(color: hint, fontSize: 12)),
                              ] else
                                Text(tr(u['status'] == 'vacant' ? '${money(toDouble(u['rent']) + toDouble(u['charges']), cur)} / mois · ${widget.manage ? 'toucher pour louer' : 'libre'}' : 'Non proposé à la location'),
                                    style: TextStyle(color: hint, fontSize: 12)),
                            ]),
                          ),
                        ),
                      ),
                    ),
                ]),
              ]),
            ),
          ),
      ]),
    );
  }
}

// ---------------------------------------------------------------------------
// Nouveau bail
// ---------------------------------------------------------------------------

Future<void> _newLease(BuildContext context, Map<String, dynamic> p, Map<String, dynamic> u, VoidCallback onDone) async {
  final ok = await showFormDialog<bool>(context, title: 'Louer ${u['reference']} — ${p['name']}', maxWidth: 628, builder: (_) => _LeaseDialog(property: p, unit: u));
  if (ok == true) {
    onDone();
    if (context.mounted) toast(context, tr('Bail enregistré : les loyers seront appelés chaque période.'));
  }
}

class _LeaseDialog extends StatefulWidget {
  const _LeaseDialog({required this.property, required this.unit});

  final Map<String, dynamic> property;
  final Map<String, dynamic> unit;

  @override
  State<_LeaseDialog> createState() => _LeaseDialogState();
}

class _LeaseDialogState extends State<_LeaseDialog> {
  final _tenant = TextEditingController();
  final _phone = TextEditingController();
  String? _tenantId;
  late final _rent = TextEditingController(text: '${toDouble(widget.unit['rent']).round()}');
  late final _charges = TextEditingController(text: '${toDouble(widget.unit['charges']).round()}');
  late final _deposit = TextEditingController(text: '${(toDouble(widget.unit['rent']) * 2).round()}');
  final _guarantor = TextEditingController();
  final _revision = TextEditingController();
  DateTime _start = DateTime(DateTime.now().year, DateTime.now().month, DateTime.now().day);
  DateTime? _end;
  int _months = 1;
  int _advance = 2;
  int _dueDay = 5;
  String _usage = 'habitation';
  bool _paidAtSigning = true;
  String _method = 'cash';
  bool _busy = false;
  late final List<Map<String, dynamic>> _partners = (LocalDb.hasEntity('partners') ? LocalDb.all('partners') : <Map<String, dynamic>>[])
      .where((p) => p['deleted_at'] == null && p['type'] != 'supplier')
      .map((p) => Map<String, dynamic>.from(p))
      .toList();

  Future<void> _save() async {
    setState(() => _busy = true);
    try {
      await context.read<Session>().api.post('immobilier/leases', {
        'unit_id': widget.unit['id'],
        if (_tenantId != null) 'tenant_id': _tenantId else ...{'tenant_name': _tenant.text.trim(), 'tenant_phone': _phone.text.trim()},
        'usage': _usage, 'start_date': isoDate(_start), if (_end != null) 'end_date': isoDate(_end!), 'rent': toDouble(_rent.text), 'charges': toDouble(_charges.text),
        'months': _months, 'due_day': _dueDay, 'deposit_amount': toDouble(_deposit.text), 'advance_months': _advance,
        if (toDouble(_revision.text) > 0) 'revision_rate': toDouble(_revision.text), if (_guarantor.text.trim().isNotEmpty) 'guarantor_name': _guarantor.text.trim(),
        if (_paidAtSigning) 'entry_payment': {'method': _method},
      });
      if (mounted) Navigator.pop(context, true);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final cur = context.watch<Session>().currency;
    final monthly = toDouble(_rent.text) + toDouble(_charges.text);
    final entry = toDouble(_deposit.text) + monthly * _advance;
    Future<void> pick(bool start) async {
      final d = await pickDate(context, initialDate: start ? _start : (_end ?? _start.add(const Duration(days: 365))), firstDate: DateTime(2015), lastDate: DateTime(2045));
      if (d != null) setState(() => start ? _start = d : _end = d);
    }

    return SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
        Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Expanded(
              child: Autocomplete<Map<String, dynamic>>(
                displayStringForOption: (p) => '${p['name']}',
                optionsBuilder: (v) => v.text.trim().length < 2 ? const Iterable.empty() : _partners.where((p) => '${p['name']} ${p['phone'] ?? ''}'.toLowerCase().contains(v.text.trim().toLowerCase())).take(8),
                onSelected: (p) => setState(() => (_tenantId = '${p['id']}', _phone.text = '${p['phone'] ?? ''}')),
                fieldViewBuilder: (c, ctl, focus, submit) => TextField(
                  controller: ctl,
                  focusNode: focus,
                  decoration: InputDecoration(labelText: tr('Locataire *'), helperText: trn(_tenantId != null ? 'Client existant' : 'Nouveau locataire (créé dans les clients)')),
                  onChanged: (v) {
                    _tenant.text = v;
                    if (_tenantId != null) setState(() => _tenantId = null);
                  },
                ),
              ),
            ),
            const SizedBox(width: 10),
            Expanded(child: TextField(controller: _phone, enabled: _tenantId == null, keyboardType: TextInputType.phone, decoration: InputDecoration(labelText: tr('Téléphone (WhatsApp)')))),
          ]),
          const SizedBox(height: 8),
          Row(children: [
            Expanded(child: OutlinedButton.icon(onPressed: () => pick(true), icon: const Icon(Icons.event, size: 18), label: Text(tr('Début : ${dateFr(isoDate(_start))}')))),
            const SizedBox(width: 10),
            Expanded(child: OutlinedButton.icon(onPressed: () => pick(false), icon: const Icon(Icons.event_busy, size: 18), label: Text(tr(_end == null ? 'Sans date de fin' : 'Fin : ${dateFr(isoDate(_end!))}')))),
          ]),
          const SizedBox(height: 8),
          Row(children: [
            Expanded(child: TextField(controller: _rent, keyboardType: TextInputType.number, onChanged: (_) => setState(() {}), decoration: InputDecoration(labelText: tr('Loyer mensuel *')))),
            const SizedBox(width: 10),
            Expanded(child: TextField(controller: _charges, keyboardType: TextInputType.number, onChanged: (_) => setState(() {}), decoration: InputDecoration(labelText: tr('Charges mensuelles')))),
          ]),
          const SizedBox(height: 8),
          Row(children: [
            Expanded(
              child: DropdownButtonFormField<int>(
                initialValue: _months,
                decoration: InputDecoration(labelText: tr('Appel du loyer')),
                items: [DropdownMenuItem(value: 1, child: Text(tr('Chaque mois'))), DropdownMenuItem(value: 3, child: Text(tr('Chaque trimestre'))), DropdownMenuItem(value: 6, child: Text(tr('Chaque semestre'))), DropdownMenuItem(value: 12, child: Text(tr('Chaque année')))],
                onChanged: (v) => setState(() => _months = v ?? 1),
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: DropdownButtonFormField<int>(
                initialValue: _dueDay,
                decoration: InputDecoration(labelText: tr('À payer avant le')),
                items: [for (final d in [1, 5, 10, 15]) DropdownMenuItem(value: d, child: Text(tr('$d du mois')))],
                onChanged: (v) => setState(() => _dueDay = v ?? 5),
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: DropdownButtonFormField<String>(
                initialValue: _usage,
                decoration: InputDecoration(labelText: tr('Usage')),
                items: [DropdownMenuItem(value: 'habitation', child: Text(tr('Habitation'))), DropdownMenuItem(value: 'professionnel', child: Text(tr('Professionnel')))],
                onChanged: (v) => setState(() => _usage = v ?? _usage),
              ),
            ),
          ]),
          const SizedBox(height: 8),
          Row(children: [
            Expanded(child: TextField(controller: _deposit, keyboardType: TextInputType.number, onChanged: (_) => setState(() {}), decoration: InputDecoration(labelText: tr('Caution')))),
            const SizedBox(width: 10),
            Expanded(
              child: DropdownButtonFormField<int>(
                initialValue: _advance,
                decoration: InputDecoration(labelText: tr('Avance (mois payés d\'avance)')),
                items: [for (final m in [0, 1, 2, 3, 6, 12]) DropdownMenuItem(value: m, child: Text(tr(m == 0 ? 'Aucune' : '$m mois')))],
                onChanged: (v) => setState(() => _advance = v ?? 0),
              ),
            ),
          ]),
          const SizedBox(height: 8),
          Row(children: [
            Expanded(child: TextField(controller: _revision, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Révision annuelle'), suffixText: '%', helperText: tr('à la date anniversaire')))),
            const SizedBox(width: 10),
            Expanded(child: TextField(controller: _guarantor, decoration: InputDecoration(labelText: tr('Garant (facultatif)')))),
          ]),
          const Divider(height: 24),
          SwitchListTile(
            contentPadding: EdgeInsets.zero,
            value: _paidAtSigning,
            onChanged: (v) => setState(() => _paidAtSigning = v),
            title: Text(tr('Caution et avance réglées à la signature : ${money(entry, cur)}')),
            subtitle: Text(tr('${money(toDouble(_deposit.text), cur)} de caution + ${_advance == 0 ? 'pas d\'avance' : '$_advance mois de loyer (le 1er au prorata)'}')),
          ),
          if (_paidAtSigning)
            DropdownButtonFormField<String>(
              initialValue: _method,
              decoration: InputDecoration(labelText: tr('Moyen de paiement')),
              items: [DropdownMenuItem(value: 'cash', child: Text(tr('Espèces'))), DropdownMenuItem(value: 'mobile_money', child: Text(tr('Mobile Money'))), DropdownMenuItem(value: 'bank_transfer', child: Text(tr('Virement'))), DropdownMenuItem(value: 'cheque', child: Text(tr('Chèque')))],
              onChanged: (v) => setState(() => _method = v ?? _method),
            ),
        ]),
        const SizedBox(height: 20),
        Align(
          alignment: Alignment.centerRight,
          child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
            TextButton(onPressed: () => Navigator.pop(context, false), child: Text(tr('Annuler'))),
            FilledButton(onPressed: _busy || (_tenantId == null && _tenant.text.trim().isEmpty) || toDouble(_rent.text) <= 0 ? null : _save, child: Text(tr('Créer le bail'))),
          ]),
        ),
      ]),
    );
  }
}

// ---------------------------------------------------------------------------
// Fiche d'un bail
// ---------------------------------------------------------------------------

Future<void> _leaseSheet(BuildContext context, int id, bool manage, VoidCallback onChanged) => showAdaptiveSheet(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (_) => FractionallySizedBox(heightFactor: .88, child: _LeaseDetail(id: id, manage: manage, onChanged: onChanged)),
    );

class _LeaseDetail extends StatefulWidget {
  const _LeaseDetail({required this.id, required this.manage, required this.onChanged});

  final int id;
  final bool manage;
  final VoidCallback onChanged;

  @override
  State<_LeaseDetail> createState() => _LeaseDetailState();
}

class _LeaseDetailState extends State<_LeaseDetail> with _Loader {
  @override
  String get path => 'immobilier/leases/${widget.id}';

  Future<void> _post(String p, Map<String, dynamic> body, String done) async {
    try {
      await session.api.post(p, body);
      await load();
      widget.onChanged();
      if (mounted) toast(context, done);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _end(Map<String, dynamic> l) async {
    final cur = session.currency;
    final retained = TextEditingController(text: '0');
    final note = TextEditingController();
    final reason = TextEditingController();
    var works = false;
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => StatefulBuilder(
        builder: (c, set) => YAlertDialog(
          title: Text(tr('Fin du bail ${l['number']}')),
          content: SizedBox(
            width: 440,
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              TextField(controller: reason, decoration: InputDecoration(labelText: tr('Motif'), hintText: tr('Départ du locataire, congé, expulsion…'))),
              const SizedBox(height: 8),
              Text(tr('Caution versée : ${money(toDouble(l['deposit_paid']), cur)}')),
              TextField(controller: retained, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Retenue sur la caution (dégâts, impayés)'))),
              TextField(controller: note, decoration: InputDecoration(labelText: tr('Justification de la retenue'))),
              SwitchListTile(contentPadding: EdgeInsets.zero, value: works, onChanged: (v) => set(() => works = v), title: Text(tr('Lot à remettre en état (en travaux)'))),
              if (toDouble(l['overdue']) > 0) Text(tr('Attention : ${money(toDouble(l['overdue']), cur)} de loyers restent impayés.'), style: const TextStyle(color: YColors.danger)),
            ]),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))),
            FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Terminer le bail'))),
          ],
        ),
      ),
    );
    if (ok == true) {
      await _post('immobilier/leases/${l['id']}/end', {'reason': reason.text.trim(), 'deposit_retained': toDouble(retained.text), 'deposit_note': note.text.trim(), 'works': works}, 'Bail terminé, lot libéré.');
    }
  }

  @override
  Widget build(BuildContext context) {
    final st = state();
    if (st != null) return st;
    final l = asMap(res!['data']);
    final rents = [for (final r in l['rents'] as List) asMap(r)];
    final cur = context.watch<Session>().currency;
    final hint = Theme.of(context).hintColor;
    final active = l['status'] == 'active';
    Widget info(String k, String v) => Padding(
          padding: const EdgeInsets.symmetric(vertical: 2),
          child: Row(children: [SizedBox(width: 170, child: Text(tr(k), style: TextStyle(color: hint))), Expanded(child: Text(tr(v), style: const TextStyle(fontWeight: FontWeight.w600)))]),
        );
    return ListView(padding: const EdgeInsets.fromLTRB(20, 0, 20, 20), children: [
      Row(children: [
        Expanded(child: Text(tr('Bail ${l['number']}'), style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800))),
        StatusChip(active ? 'En cours' : 'Terminé', color: active ? YColors.success : YColors.muted),
      ]),
      Text(tr('${l['property_name']} — ${l['unit_reference']} · ${(l['tenant'] as Map?)?['name'] ?? ''}${(l['tenant'] as Map?)?['phone'] != null ? ' · ${(l['tenant'] as Map)['phone']}' : ''}'), style: TextStyle(color: hint)),
      const SizedBox(height: 12),
      KpiWrap(children: [
        _kpi(context, 'Loyer + charges / mois', money(toDouble(l['monthly']), cur), sub: '${l['periodicity']} · avant le ${l['due_day']}'),
        _kpi(context, 'Impayés', money(toDouble(l['overdue']), cur), sub: '${l['overdue_count']} loyer(s) échu(s)', color: toDouble(l['overdue']) > 0 ? YColors.danger : YColors.success),
        _kpi(context, 'Caution', money(toDouble(l['deposit_paid']), cur), sub: toDouble(l['deposit_due']) > 0 ? 'reste ${money(toDouble(l['deposit_due']), cur)}' : 'versée'),
      ]),
      const SizedBox(height: 8),
      info('Début', dateFr(l['start_date'])),
      info('Fin', l['end_date'] == null ? 'Sans date de fin' : dateFr(l['end_date'])),
      info('Usage', l['usage'] == 'professionnel' ? 'Professionnel' : 'Habitation'),
      if (toDouble(l['revision_rate']) > 0) info('Révision', '+${number(toDouble(l['revision_rate']), decimals: 1)} % le ${dateFr(l['next_revision_date'])}'),
      if (l['guarantor_name'] != null) info('Garant', '${l['guarantor_name']}${l['guarantor_phone'] != null ? ' · ${l['guarantor_phone']}' : ''}'),
      if (l['next_period'] != null) info('Prochain appel', 'période du ${dateFr(l['next_period'])}'),
      if (!active) info('Terminé le', '${dateFr(l['ended_at'])}${l['end_reason'] != null ? ' · ${l['end_reason']}' : ''}'),
      if (!active && toDouble(l['deposit_paid']) > 0) info('Caution', 'restituée ${money(toDouble(l['deposit_returned']), cur)} · retenue ${money(toDouble(l['deposit_retained']), cur)}'),
      if (l['last_error'] != null) Padding(padding: const EdgeInsets.only(top: 6), child: Text(tr('${l['last_error']}'), style: const TextStyle(color: YColors.danger))),
      const SizedBox(height: 12),
      Wrap(spacing: 8, runSpacing: 8, children: [
        if (widget.manage && active && toDouble(l['overdue']) > 0)
          FilledButton.icon(onPressed: () => _post('immobilier/leases/${l['id']}/remind', {}, 'Relance envoyée.'), icon: const Icon(Icons.send_outlined, size: 18), label: Text(tr('Relancer'))),
        if (l['whatsapp_url'] != null) OutlinedButton.icon(onPressed: () => openUrl('${l['whatsapp_url']}'), icon: const Icon(Icons.chat_outlined, size: 18), label: Text(tr('WhatsApp'))),
        if (widget.manage && active && toDouble(l['deposit_due']) > 0)
          OutlinedButton.icon(
            onPressed: () => _post('immobilier/leases/${l['id']}/deposit', {'amount': toDouble(l['deposit_due'])}, 'Caution encaissée.'),
            icon: const Icon(Icons.lock_outline, size: 18),
            label: Text(tr('Caution reçue (${money(toDouble(l['deposit_due']), cur)})')),
          ),
        if (widget.manage && active) TextButton.icon(style: TextButton.styleFrom(foregroundColor: YColors.danger), onPressed: () => _end(l), icon: const Icon(Icons.logout, size: 18), label: Text(tr('Fin du bail'))),
      ]),
      const Divider(height: 28),
      Text(tr('Loyers'), style: TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
      const SizedBox(height: 6),
      if (rents.isEmpty) Text(tr('Aucun loyer appelé pour le moment.'), style: TextStyle(color: hint)),
      for (final r in rents) _RentTile(rent: r, manage: widget.manage, onChanged: () { load(); widget.onChanged(); }, showTenant: false),
    ]);
  }
}

// ---------------------------------------------------------------------------
// Ligne de loyer (encaissement, quittance)
// ---------------------------------------------------------------------------

class _RentTile extends StatelessWidget {
  const _RentTile({required this.rent, required this.manage, required this.onChanged, this.showTenant = true});

  final Map<String, dynamic> rent;
  final bool manage;
  final VoidCallback onChanged;
  final bool showTenant;

  Future<void> _pay(BuildContext context) async {
    final cur = context.read<Session>().currency;
    final amount = TextEditingController(text: '${toDouble(rent['balance']).round()}');
    final ref = TextEditingController();
    var method = 'cash';
    var date = DateTime.now();
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => StatefulBuilder(
        builder: (c, set) => YAlertDialog(
          title: Text(tr('Encaisser le loyer de ${rent['label']}')),
          content: SizedBox(
            width: 420,
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              Text(tr('${(rent['tenant'] as Map?)?['name'] ?? ''} · ${rent['property_name']} — ${rent['unit_reference']} · reste ${money(toDouble(rent['balance']), cur)}')),
              const SizedBox(height: 8),
              TextField(controller: amount, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Montant reçu'))),
              const SizedBox(height: 8),
              DropdownButtonFormField<String>(
                initialValue: method,
                decoration: InputDecoration(labelText: tr('Moyen de paiement')),
                items: [DropdownMenuItem(value: 'cash', child: Text(tr('Espèces'))), DropdownMenuItem(value: 'mobile_money', child: Text(tr('Mobile Money'))), DropdownMenuItem(value: 'bank_transfer', child: Text(tr('Virement'))), DropdownMenuItem(value: 'cheque', child: Text(tr('Chèque')))],
                onChanged: (v) => set(() => method = v ?? method),
              ),
              const SizedBox(height: 8),
              TextField(controller: ref, decoration: InputDecoration(labelText: trn(method == 'mobile_money' ? 'N° de transaction' : 'Référence (facultatif)'))),
              const SizedBox(height: 8),
              OutlinedButton.icon(
                onPressed: () async {
                  final d = await pickDate(c, initialDate: date, firstDate: DateTime(2015), lastDate: DateTime.now());
                  if (d != null) set(() => date = d);
                },
                icon: const Icon(Icons.event, size: 18),
                label: Text(tr('Reçu le ${dateFr(isoDate(date))}')),
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
    if (ok != true || !context.mounted) return;
    try {
      await context.read<Session>().api.post('immobilier/rents/${rent['id']}/pay', {'amount': toDouble(amount.text), 'method': method, 'date': isoDate(date), if (ref.text.trim().isNotEmpty) 'reference': ref.text.trim()});
      onChanged();
      if (context.mounted) toast(context, tr('Loyer encaissé.'));
    } catch (e) {
      if (context.mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final cur = context.watch<Session>().currency;
    final hint = Theme.of(context).hintColor;
    final s = '${rent['status']}';
    return Card(
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
        child: Row(children: [
          CircleAvatar(radius: 18, backgroundColor: _rentColor(s).withValues(alpha: .12), child: Icon(s == 'paid' ? Icons.check : Icons.receipt_outlined, color: _rentColor(s), size: 18)),
          const SizedBox(width: 12),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(tr(showTenant ? '${(rent['tenant'] as Map?)?['name'] ?? '—'} · ${rent['property_name']} — ${rent['unit_reference']}' : 'Loyer ${rent['label']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
              Text(tr([
                if (showTenant) '${rent['label']}',
                'échéance ${dateFr(rent['due_date'])}',
                '${rent['invoice_number'] ?? ''}',
                if (toDouble(rent['days_late']) > 0) 'retard ${rent['days_late']} j',
                if (rent['reminded_at'] != null) 'relancé ${rent['reminders']} fois',
              ].join(' · ')), style: TextStyle(color: toDouble(rent['days_late']) > 0 ? YColors.danger : hint, fontSize: 12.5)),
            ]),
          ),
          Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
            Text(tr(money(toDouble(rent['total']), cur)), style: const TextStyle(fontWeight: FontWeight.w800)),
            if (s == 'partial') Text(tr('reste ${money(toDouble(rent['balance']), cur)}'), style: const TextStyle(color: YColors.warning, fontSize: 12)),
            StatusChip('${rent['status_label']}', color: _rentColor(s)),
          ]),
          const SizedBox(width: 6),
          if (manage && (s == 'unpaid' || s == 'partial')) IconButton(tooltip: tr('Encaisser'), onPressed: () => _pay(context), icon: const Icon(Icons.payments_outlined)),
          IconButton(
            tooltip: trn(s == 'paid' ? 'Quittance' : 'Avis d\'échéance'),
            onPressed: () => _openPdf(context, 'immobilier/rents/${rent['id']}/pdf', s == 'paid' ? 'Quittance ${rent['label']}' : 'Avis d\'échéance ${rent['label']}'),
            icon: Icon(s == 'paid' ? Icons.verified_outlined : Icons.picture_as_pdf_outlined),
          ),
        ]),
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Baux
// ---------------------------------------------------------------------------

class _Leases extends StatefulWidget {
  const _Leases({super.key, required this.manage, required this.onChanged});

  final bool manage;
  final VoidCallback onChanged;

  @override
  State<_Leases> createState() => _LeasesState();
}

class _LeasesState extends State<_Leases> with _Loader {
  String _status = 'active';
  String _q = '';
  final Map<String, String?> _f = {};
  FilterPeriod? _period;
  int _gen = 0;

  @override
  String get path => 'immobilier/leases';

  @override
  Map<String, dynamic>? get query => {'status': _status.isEmpty ? 'all' : _status};

  @override
  Widget build(BuildContext context) {
    final st = state();
    if (st != null) return st;
    final all = [for (final l in res!['data'] as List) asMap(l)];
    final cur = context.watch<Session>().currency;
    final hint = Theme.of(context).hintColor;
    String tenant(Map<String, dynamic> l) => '${(l['tenant'] as Map?)?['name'] ?? ''}';
    final rows = all
        .where((l) => _q.isEmpty || '${tenant(l)} ${l['property_name']} ${l['unit_reference']} ${l['number']}'.toLowerCase().contains(_q))
        .where((l) => _f['property'] == null || '${l['property_name']}' == _f['property'])
        .where((l) => _f['tenant'] == null || tenant(l) == _f['tenant'])
        .where((l) => _f['overdue'] == null || (toDouble(l['overdue']) > 0) == (_f['overdue'] == 'yes'))
        .where((l) => _f['periodicity'] == null || '${l['periodicity']}' == _f['periodicity'])
        .where((l) => _period == null || _period!.contains(l['start_date']))
        .toList();
    return RefreshIndicator(
      onRefresh: load,
      child: ListView(padding: const EdgeInsets.all(16), children: [
        FilterBar(
          key: ValueKey(_gen),
          padding: const EdgeInsets.only(bottom: 10),
          onSearch: (v) => setState(() => _q = v.toLowerCase()),
          search: _q,
          searchHint: 'Locataire, bien, lot, n°…',
          statuses: const {'': 'Tous', 'active': 'En cours', 'ended': 'Terminés'},
          status: _status,
          onStatus: (v) => setState(() => (_status = v, load())),
          filters: [
            FilterSpec('property', 'Bien', filterOptions(all, 'property_name'), icon: Icons.apartment),
            FilterSpec('tenant', 'Locataire', {for (final l in all) if (tenant(l).isNotEmpty) tenant(l): tenant(l)}, icon: Icons.person_outline),
            const FilterSpec('overdue', 'Impayés', {'yes': 'Avec impayés', 'no': 'À jour'}, icon: Icons.warning_amber_rounded),
            FilterSpec('periodicity', 'Périodicité', filterOptions(all, 'periodicity'), icon: Icons.repeat),
          ],
          values: _f,
          onFilter: (k, v) => setState(() => _f[k] = v),
          showPeriod: true,
          period: _period,
          onPeriod: (p) => setState(() => _period = p),
          count: rows.length,
          countLabel: 'bail',
          onReset: () => setState(() {
            _q = '';
            _f.clear();
            _period = null;
            _gen++;
            if (_status != 'active') {
              _status = 'active';
              load();
            }
          }),
        ),
        if (rows.isEmpty) EmptyState(icon: Icons.description_outlined, title: tr('Aucun bail'), message: tr('Créez un bail en touchant un lot libre dans « Biens & lots ».')),
        for (final l in rows)
          Card(
            child: ListTile(
              onTap: () => _leaseSheet(context, l['id'] as int, widget.manage, () { load(); widget.onChanged(); }),
              leading: CircleAvatar(
                backgroundColor: (toDouble(l['overdue']) > 0 ? YColors.danger : YColors.success).withValues(alpha: .12),
                child: Icon(Icons.key_outlined, color: toDouble(l['overdue']) > 0 ? YColors.danger : YColors.success),
              ),
              title: Text(tr('${(l['tenant'] as Map?)?['name'] ?? '—'} · ${l['property_name']} — ${l['unit_reference']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
              subtitle: Text(tr('${l['number']} · depuis le ${dateFr(l['start_date'])} · ${l['periodicity']}${l['end_date'] != null ? ' · fin ${dateFr(l['end_date'])}' : ''}'), style: TextStyle(color: hint)),
              trailing: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.end, children: [
                Text(tr('${money(toDouble(l['monthly']), cur)} / mois'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15)),
                if (toDouble(l['overdue']) > 0) Text(tr('impayé ${money(toDouble(l['overdue']), cur)}'), style: const TextStyle(color: YColors.danger, fontSize: 13)),
              ]),
            ),
          ),
      ]),
    );
  }
}

// ---------------------------------------------------------------------------
// Loyers
// ---------------------------------------------------------------------------

class _Rents extends StatefulWidget {
  const _Rents({super.key, required this.manage, required this.onChanged});

  final bool manage;
  final VoidCallback onChanged;

  @override
  State<_Rents> createState() => _RentsState();
}

class _RentsState extends State<_Rents> with _Loader {
  DateTime _month = DateTime(DateTime.now().year, DateTime.now().month);
  String _status = '';
  String _q = '';
  final Map<String, String?> _f = {};
  int _gen = 0;

  @override
  String get path => 'immobilier/rents';

  @override
  Map<String, dynamic>? get query => {'month': _ym(_month), 'status': _status.isEmpty ? 'all' : _status};

  @override
  Widget build(BuildContext context) {
    final st = state();
    if (st != null) return st;
    final all = [for (final r in res!['data'] as List) asMap(r)];
    String tenant(Map<String, dynamic> r) => '${(r['tenant'] as Map?)?['name'] ?? ''}';
    final rows = all
        .where((r) => _q.isEmpty || '${tenant(r)} ${r['property_name']} ${r['unit_reference']} ${r['label']} ${r['invoice_number'] ?? ''}'.toLowerCase().contains(_q))
        .where((r) => _f['property'] == null || '${r['property_name']}' == _f['property'])
        .where((r) => _f['tenant'] == null || tenant(r) == _f['tenant'])
        .where((r) => _f['late'] == null || (toDouble(r['days_late']) > 0) == (_f['late'] == 'yes'))
        .toList();
    final cur = context.watch<Session>().currency;
    final total = rows.fold<double>(0, (a, r) => a + toDouble(r['total']));
    final paid = rows.fold<double>(0, (a, r) => a + toDouble(r['paid']));
    final balance = rows.fold<double>(0, (a, r) => a + toDouble(r['balance']));
    return RefreshIndicator(
      onRefresh: load,
      child: ListView(padding: const EdgeInsets.all(16), children: [
        FilterBar(
          key: ValueKey(_gen),
          padding: const EdgeInsets.only(bottom: 10),
          onSearch: (v) => setState(() => _q = v.toLowerCase()),
          search: _q,
          searchHint: 'Locataire, bien, lot, facture…',
          statuses: const {'': 'Tous', 'open': 'À payer', 'paid': 'Payés', 'overdue': 'Tous les impayés échus'},
          status: _status,
          onStatus: (v) => setState(() => (_status = v, load())),
          filters: [
            FilterSpec('property', 'Bien', filterOptions(all, 'property_name'), icon: Icons.apartment),
            FilterSpec('tenant', 'Locataire', {for (final r in all) if (tenant(r).isNotEmpty) tenant(r): tenant(r)}, icon: Icons.person_outline),
            const FilterSpec('late', 'Retard', {'yes': 'En retard', 'no': 'Dans les temps'}, icon: Icons.alarm),
          ],
          values: _f,
          onFilter: (k, v) => setState(() => _f[k] = v),
          count: rows.length,
          countLabel: 'loyer',
          onReset: () => setState(() {
            _q = '';
            _f.clear();
            _gen++;
            if (_status.isNotEmpty) {
              _status = '';
              load();
            }
          }),
          trailing: [
            if (_status != 'overdue') ...[
              IconButton(tooltip: tr('Mois précédent'), onPressed: () => setState(() => (_month = DateTime(_month.year, _month.month - 1), load())), icon: const Icon(Icons.chevron_left)),
              Text(tr(_monthLabel(_month)), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
              IconButton(tooltip: tr('Mois suivant'), onPressed: () => setState(() => (_month = DateTime(_month.year, _month.month + 1), load())), icon: const Icon(Icons.chevron_right)),
            ],
          ],
        ),
        KpiWrap(children: [
          _kpi(context, 'Appelé', money(total, cur), sub: '${rows.length} loyer(s)'),
          _kpi(context, 'Encaissé', money(paid, cur), color: YColors.success),
          _kpi(context, 'Reste à encaisser', money(balance, cur), color: balance > 0 ? YColors.danger : YColors.success),
        ]),
        const SizedBox(height: 10),
        if (rows.isEmpty)
          EmptyState(icon: Icons.receipt_long_outlined, title: tr('Aucun loyer'), message: tr('Les appels de loyer sont émis automatiquement chaque matin, quelques jours avant chaque période, ou avec le bouton en haut.')),
        for (final r in rows) _RentTile(rent: r, manage: widget.manage, onChanged: () { load(); widget.onChanged(); }),
      ]),
    );
  }
}

// ---------------------------------------------------------------------------
// Dépenses
// ---------------------------------------------------------------------------

class _Expenses extends StatefulWidget {
  const _Expenses({super.key, required this.manage});

  final bool manage;

  @override
  State<_Expenses> createState() => _ExpensesState();
}

class _ExpensesState extends State<_Expenses> with _Loader {
  List<Map<String, dynamic>> _props = [];
  Map<String, dynamic> _cats = {};
  String _q = '';
  final Map<String, String?> _f = {};
  FilterPeriod? _period;
  int _gen = 0;

  @override
  String get path => 'immobilier/expenses';

  @override
  Future<void> load() async {
    await super.load();
    try {
      final p = await session.api.get('immobilier/properties');
      if (mounted) {
        setState(() => (
              _props = [for (final x in p['data'] as List) asMap(x)],
              _cats = asMap((p['meta'] as Map)['expense_categories']),
            ));
      }
    } catch (_) {}
  }

  Future<void> _add() async {
    if (_props.isEmpty) {
      toast(context, tr('Ajoutez d\'abord un bien.'), error: true);
      return;
    }
    var prop = _props.first['id'] as int;
    int? unit;
    var cat = 'travaux';
    var owner = true;
    var date = DateTime.now();
    final label = TextEditingController();
    final amount = TextEditingController();
    final supplier = TextEditingController();
    final ok = await showFormDialog<bool>(context, title: 'Nouvelle dépense', builder: (c) => StatefulBuilder(builder: (c, set) {
        final units = [for (final u in (_props.firstWhere((p) => p['id'] == prop)['units'] as List)) asMap(u)];
        return SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
            Column(mainAxisSize: MainAxisSize.min, children: [
              DropdownButtonFormField<int>(
                initialValue: prop,
                decoration: InputDecoration(labelText: tr('Bien')),
                items: [for (final p in _props) DropdownMenuItem(value: p['id'] as int, child: Text(tr('${p['name']}')))],
                onChanged: (v) => set(() => (prop = v ?? prop, unit = null)),
              ),
              DropdownButtonFormField<int?>(
                initialValue: unit,
                decoration: InputDecoration(labelText: tr('Lot (facultatif)')),
                items: [DropdownMenuItem(value: null, child: Text(tr('Tout le bien'))), for (final u in units) DropdownMenuItem(value: u['id'] as int, child: Text(tr('${u['reference']}')))],
                onChanged: (v) => set(() => unit = v),
              ),
              DropdownButtonFormField<String>(
                initialValue: cat,
                decoration: InputDecoration(labelText: tr('Catégorie')),
                items: [for (final e in _cats.entries) DropdownMenuItem(value: e.key, child: Text(tr('${e.value}')))],
                onChanged: (v) => set(() => cat = v ?? cat),
              ),
              TextField(controller: label, decoration: InputDecoration(labelText: tr('Libellé *'), hintText: tr('Réparation plomberie, taxe foncière…'))),
              Row(children: [
                Expanded(child: TextField(controller: amount, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Montant *')))),
                const SizedBox(width: 10),
                Expanded(child: TextField(controller: supplier, decoration: InputDecoration(labelText: tr('Prestataire')))),
              ]),
              const SizedBox(height: 8),
              OutlinedButton.icon(
                onPressed: () async {
                  final d = await pickDate(c, initialDate: date, firstDate: DateTime(2015), lastDate: DateTime.now().add(const Duration(days: 30)));
                  if (d != null) set(() => date = d);
                },
                icon: const Icon(Icons.event, size: 18),
                label: Text(tr('Le ${dateFr(isoDate(date))}')),
              ),
              SwitchListTile(contentPadding: EdgeInsets.zero, value: owner, onChanged: (v) => set(() => owner = v), title: Text(tr('À la charge du propriétaire')), subtitle: Text(tr('Déduite de son reversement'))),
            ]),
            const SizedBox(height: 20),
            Align(
              alignment: Alignment.centerRight,
              child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
                TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))),
                FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Enregistrer'))),
              ]),
            ),
          ]),
        );
      }));
    if (ok != true) return;
    try {
      await session.api.post('immobilier/expenses', {
        'property_id': prop, 'unit_id': ?unit, 'category': cat, 'label': label.text.trim(), 'amount': toDouble(amount.text), 'date': isoDate(date),
        'owner_charge': owner, if (supplier.text.trim().isNotEmpty) 'supplier': supplier.text.trim(),
      });
      await load();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final st = state();
    if (st != null) return st;
    final all = [for (final r in res!['data'] as List) asMap(r)];
    final cur = context.watch<Session>().currency;
    final hint = Theme.of(context).hintColor;
    final rows = all
        .where((x) => _q.isEmpty || '${x['label']} ${x['property_name']} ${x['unit_reference'] ?? ''} ${x['supplier'] ?? ''} ${x['category_label'] ?? ''}'.toLowerCase().contains(_q))
        .where((x) => _f['property'] == null || '${x['property_name']}' == _f['property'])
        .where((x) => _f['category'] == null || '${x['category']}' == _f['category'])
        .where((x) => _f['charge'] == null || (x['owner_charge'] == true ? 'owner' : 'agency') == _f['charge'])
        .where((x) => _f['supplier'] == null || '${x['supplier']}' == _f['supplier'])
        .where((x) => _period == null || _period!.contains(x['date']))
        .toList();
    final shownTotal = rows.fold<double>(0, (a, x) => a + toDouble(x['amount']));
    return RefreshIndicator(
      onRefresh: load,
      child: ListView(padding: const EdgeInsets.all(16), children: [
        FilterBar(
          key: ValueKey(_gen),
          padding: const EdgeInsets.only(bottom: 10),
          onSearch: (v) => setState(() => _q = v.toLowerCase()),
          search: _q,
          searchHint: 'Libellé, bien, prestataire…',
          filters: [
            FilterSpec('property', 'Bien', filterOptions(all, 'property_name'), icon: Icons.apartment),
            FilterSpec('category', 'Catégorie', filterOptions(all, 'category', label: (x) => '${x['category_label'] ?? _cats[x['category']] ?? x['category']}'), icon: Icons.category_outlined),
            const FilterSpec('charge', 'À la charge', {'owner': 'Du propriétaire', 'agency': 'De l\'agence'}, icon: Icons.account_balance_wallet_outlined),
            FilterSpec('supplier', 'Prestataire', filterOptions(all, 'supplier'), icon: Icons.handyman_outlined),
          ],
          values: _f,
          onFilter: (k, v) => setState(() => _f[k] = v),
          showPeriod: true,
          period: _period,
          onPeriod: (p) => setState(() => _period = p),
          count: rows.length,
          countLabel: 'dépense',
          onReset: () => setState(() {
            _q = '';
            _f.clear();
            _period = null;
            _gen++;
          }),
          trailing: [
            if (rows.isNotEmpty) Text(tr('Total ${money(shownTotal, cur)}'), style: const TextStyle(fontWeight: FontWeight.w700)),
            if (widget.manage) FilledButton.tonalIcon(onPressed: _add, icon: const Icon(Icons.add), label: Text(tr('Nouvelle dépense'))),
          ],
        ),
        if (rows.isEmpty) EmptyState(icon: Icons.handyman_outlined, title: tr('Aucune dépense'), message: tr('Travaux, entretien, taxes, gardiennage : à la charge du propriétaire, elles sont déduites de son relevé de gérance.')),
        for (final x in rows)
          Card(
            child: ListTile(
              leading: const CircleAvatar(backgroundColor: Color(0x1AF59E0B), child: Icon(Icons.handyman_outlined, color: YColors.warning)),
              title: Text(tr('${x['label']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
              subtitle: Text(tr([dateFr(x['date']), '${x['property_name']}${x['unit_reference'] != null ? ' — ${x['unit_reference']}' : ''}', '${x['category_label']}', if (x['supplier'] != null) '${x['supplier']}', x['owner_charge'] == true ? 'à la charge du propriétaire' : 'à la charge de l\'agence'].join(' · ')),
                  style: TextStyle(color: hint)),
              trailing: Wrap(crossAxisAlignment: WrapCrossAlignment.center, children: [
                Text(tr(money(toDouble(x['amount']), cur)), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15)),
                if (widget.manage)
                  IconButton(
                    onPressed: () async {
                      if (!await confirm(context, tr('Supprimer cette dépense ?'), '${x['label']}', ok: tr('Supprimer'), danger: true)) return;
                      try {
                        await session.api.delete('immobilier/expenses/${x['id']}');
                        await load();
                      } catch (e) {
                        if (context.mounted) toast(context, errorText(e), error: true);
                      }
                    },
                    icon: const Icon(Icons.delete_outline, size: 20),
                  ),
              ]),
            ),
          ),
      ]),
    );
  }
}

// ---------------------------------------------------------------------------
// Propriétaires : relevés de gérance et reversements
// ---------------------------------------------------------------------------

class _Owners extends StatefulWidget {
  const _Owners({super.key});

  @override
  State<_Owners> createState() => _OwnersState();
}

class _OwnersState extends State<_Owners> with _Loader {
  String? _owner;
  bool _picked = false;
  DateTime _month = DateTime(DateTime.now().year, DateTime.now().month - 1);
  Map<String, dynamic>? _st;

  @override
  String get path => 'immobilier/owners';

  @override
  Future<void> load() async {
    await super.load();
    final list = (res?['data'] as List?) ?? [];
    if (!_picked && list.isNotEmpty && mounted) {
      final first = list.map((o) => asMap(o)).firstWhere((o) => o['id'] != null, orElse: () => {'id': null});
      setState(() => (_owner = first['id'] as String?, _picked = true));
      await _loadStatement();
    }
  }

  Map<String, String> get _q => {
        'owner_id': ?_owner,
        'from': isoDate(DateTime(_month.year, _month.month)),
        'to': isoDate(DateTime(_month.year, _month.month + 1, 0)),
      };

  Future<void> _loadStatement() async {
    setState(() => _st = null);
    try {
      final r = await session.api.get('immobilier/statement', query: _q);
      if (mounted) setState(() => _st = asMap(r['data']));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _payout(Map<String, dynamic> s) async {
    final cur = session.currency;
    final amount = TextEditingController(text: '${toDouble(s['to_pay']).round()}');
    final ref = TextEditingController();
    var method = 'bank_transfer';
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => StatefulBuilder(
        builder: (c, set) => YAlertDialog(
          title: Text(tr('Reversement à ${(s['owner'] as Map)['name']}')),
          content: SizedBox(
            width: 420,
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              Text(tr('Net de ${_monthLabel(_month)} : ${money(toDouble((s['totals'] as Map)['net']), cur)}')),
              TextField(controller: amount, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Montant reversé'))),
              DropdownButtonFormField<String>(
                initialValue: method,
                decoration: InputDecoration(labelText: tr('Moyen')),
                items: [DropdownMenuItem(value: 'bank_transfer', child: Text(tr('Virement'))), DropdownMenuItem(value: 'mobile_money', child: Text(tr('Mobile Money'))), DropdownMenuItem(value: 'cheque', child: Text(tr('Chèque'))), DropdownMenuItem(value: 'cash', child: Text(tr('Espèces')))],
                onChanged: (v) => set(() => method = v ?? method),
              ),
              TextField(controller: ref, decoration: InputDecoration(labelText: tr('Référence'))),
            ]),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))),
            FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Enregistrer'))),
          ],
        ),
      ),
    );
    if (ok != true) return;
    try {
      await session.api.post('immobilier/payouts', {
        'owner_id': _owner, 'period_from': _q['from'], 'period_to': _q['to'], 'amount': toDouble(amount.text), 'method': method, if (ref.text.trim().isNotEmpty) 'reference': ref.text.trim(),
      });
      await _loadStatement();
      if (mounted) toast(context, tr('Reversement enregistré.'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final st = state();
    if (st != null) return st;
    final owners = [for (final o in res!['data'] as List) asMap(o)];
    final cur = context.watch<Session>().currency;
    final hint = Theme.of(context).hintColor;
    final s = _st;
    return ListView(padding: const EdgeInsets.all(16), children: [
      Wrap(spacing: 10, runSpacing: 10, crossAxisAlignment: WrapCrossAlignment.center, children: [
        SizedBox(
          width: 320,
          child: DropdownButtonFormField<String?>(
            initialValue: _owner,
            decoration: InputDecoration(labelText: tr('Propriétaire')),
            items: [for (final o in owners) DropdownMenuItem(value: o['id'] as String?, child: Text(tr('${o['name']} (${o['units']} lot(s))')))],
            onChanged: (v) {
              setState(() => (_owner = v, _picked = true));
              _loadStatement();
            },
          ),
        ),
        IconButton(onPressed: () => setState(() => (_month = DateTime(_month.year, _month.month - 1), _picked ? _loadStatement() : null)), icon: const Icon(Icons.chevron_left)),
        Text(tr(_monthLabel(_month)), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
        IconButton(onPressed: () => setState(() => (_month = DateTime(_month.year, _month.month + 1), _picked ? _loadStatement() : null)), icon: const Icon(Icons.chevron_right)),
        if (s != null) OutlinedButton.icon(onPressed: () => _openPdf(context, 'immobilier/statement/pdf', 'Relevé de gérance', query: _q), icon: const Icon(Icons.picture_as_pdf_outlined, size: 18), label: Text(tr('Relevé PDF'))),
        if (s != null && s['owner'] != null && toDouble(s['to_pay']) > 0)
          FilledButton.icon(onPressed: () => _payout(s), icon: const Icon(Icons.outbound_outlined, size: 18), label: Text(tr('Reverser ${money(toDouble(s['to_pay']), cur)}'))),
      ]),
      const SizedBox(height: 12),
      if (!_picked)
        EmptyState(icon: Icons.account_balance_outlined, title: tr('Relevé de gérance'), message: tr('Choisissez un propriétaire : loyers appelés et encaissés lot par lot, honoraires, dépenses déduites, net à reverser et impayés.'))
      else if (s == null)
        const LoadingSkeleton()
      else ...[
        KpiWrap(children: [
          _kpi(context, 'Loyers encaissés', money(toDouble((s['totals'] as Map)['collected']), cur), sub: 'appelés ${money(toDouble((s['totals'] as Map)['billed']), cur)}'),
          _kpi(context, 'Honoraires', money(toDouble((s['totals'] as Map)['fees']), cur)),
          _kpi(context, 'Dépenses déduites', money(toDouble((s['totals'] as Map)['expenses']), cur)),
          _kpi(context, 'Net propriétaire', money(toDouble((s['totals'] as Map)['net']), cur), color: YColors.success,
              sub: toDouble(s['paid_out']) > 0 ? 'reversé ${money(toDouble(s['paid_out']), cur)}' : 'reste ${money(toDouble(s['to_pay']), cur)}'),
          _kpi(context, 'Impayés locataires', money(toDouble((s['totals'] as Map)['overdue']), cur), color: YColors.danger),
        ]),
        const SizedBox(height: 10),
        for (final p in (s['properties'] as List).map((x) => asMap(x)))
          SectionCard(
            title: '${p['name']}${toDouble(p['fee_rate']) > 0 ? ' · honoraires ${number(toDouble(p['fee_rate']), decimals: 1)} %' : ''}',
            child: Column(children: [
              Row(children: [
                SizedBox(width: 110, child: Text(tr('Lot'), style: TextStyle(color: hint, fontSize: 12))),
                Expanded(child: Text(tr('Locataire'), style: TextStyle(color: hint, fontSize: 12))),
                SizedBox(width: 120, child: Text(tr('Encaissé'), textAlign: TextAlign.right, style: TextStyle(color: hint, fontSize: 12))),
                SizedBox(width: 120, child: Text(tr('Impayés'), textAlign: TextAlign.right, style: TextStyle(color: hint, fontSize: 12))),
              ]),
              for (final u in (p['units'] as List).map((x) => asMap(x)))
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 4),
                  child: Row(children: [
                    SizedBox(width: 110, child: Text(tr('${u['unit']}'), style: const TextStyle(fontWeight: FontWeight.w600))),
                    Expanded(child: Text(tr('${u['tenant'] ?? 'Libre'}'), style: TextStyle(color: hint))),
                    SizedBox(width: 120, child: Text(tr(money(toDouble(u['collected']), cur)), textAlign: TextAlign.right)),
                    SizedBox(width: 120, child: Text(tr(toDouble(u['overdue']) > 0 ? money(toDouble(u['overdue']), cur) : '—'), textAlign: TextAlign.right, style: TextStyle(color: toDouble(u['overdue']) > 0 ? YColors.danger : hint))),
                  ]),
                ),
              const Divider(),
              if (toDouble(p['fees']) > 0 || toDouble(p['expenses']) > 0)
                Padding(
                  padding: const EdgeInsets.only(bottom: 4),
                  child: Row(children: [
                    Expanded(child: Text(tr('Honoraires ${money(toDouble(p['fees']), cur)} · dépenses ${money(toDouble(p['expenses']), cur)}'), style: TextStyle(color: hint))),
                  ]),
                ),
              Row(children: [
                Expanded(child: Text(tr('Net du bien'), style: TextStyle(fontWeight: FontWeight.w700))),
                Text(tr(money(toDouble(p['net']), cur)), style: const TextStyle(fontWeight: FontWeight.w800)),
              ]),
            ]),
          ),
        if ((s['payouts'] as List? ?? []).isNotEmpty)
          SectionCard(
            title: tr('Reversements'),
            child: Column(children: [
              for (final x in (s['payouts'] as List).map((y) => asMap(y)))
                ListTile(
                  dense: true,
                  contentPadding: EdgeInsets.zero,
                  title: Text(tr('${dateFr(x['paid_at'])} · ${money(toDouble(x['amount']), cur)}')),
                  subtitle: Text(tr('Période du ${dateFr(x['period_from'])} au ${dateFr(x['period_to'])}${x['reference'] != null ? ' · ${x['reference']}' : ''}')),
                  trailing: IconButton(
                    icon: const Icon(Icons.copy, size: 18),
                    tooltip: tr('Copier'),
                    onPressed: () => Clipboard.setData(ClipboardData(text: '${dateFr(x['paid_at'])} — ${money(toDouble(x['amount']), cur)}')),
                  ),
                ),
            ]),
          ),
      ],
    ]);
  }
}
