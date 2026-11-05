import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/local_db.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/report_charts.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

/// Multi-agences : tableau de bord comparatif et paramétrage des agences (dépôt, caisse, équipe).
class AgencesScreen extends StatefulWidget {
  const AgencesScreen({super.key, this.standalone = false, this.initialTab = 0});

  final bool standalone;
  final int initialTab;

  @override
  State<AgencesScreen> createState() => _AgencesScreenState();
}

class _AgencesScreenState extends State<AgencesScreen> with SingleTickerProviderStateMixin {
  late final bool _manage = context.read<Session>().can('agences.manage');
  late final bool _view = context.read<Session>().can('agences.view') || _manage;
  late final TabController _tabs = TabController(length: (_view ? 1 : 0) + (_manage ? 1 : 0), vsync: this, initialIndex: _view && _manage ? widget.initialTab.clamp(0, 1) : 0);

  @override
  Widget build(BuildContext context) {
    final body = Column(children: [
      PageHeader(title: tr('Agences'), subtitle: tr('Ventes, marge, dépenses, trésorerie et stock de chaque agence · dépôts, caisses et équipes')),
      TabBar(controller: _tabs, isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [
        if (_view) Tab(text: tr('Tableau de bord')),
        if (_manage) Tab(text: tr('Paramétrage')),
      ]),
      Expanded(
        child: TabBarView(controller: _tabs, children: [
          if (_view) const _Summary(),
          if (_manage) const _Manage(),
        ]),
      ),
    ]);
    return widget.standalone ? Scaffold(appBar: AppBar(title: Text(tr('Agences'))), body: body) : body;
  }
}

// ---------------------------------------------------------------------------
// Tableau de bord comparatif
// ---------------------------------------------------------------------------

class _Summary extends StatefulWidget {
  const _Summary();

  @override
  State<_Summary> createState() => _SummaryState();
}

class _SummaryState extends State<_Summary> {
  late DateTimeRange _range = DateTimeRange(start: DateTime(DateTime.now().year), end: DateTime.now());
  Map<String, dynamic>? _d;
  Object? _error;
  String _sort = 'sales';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('agences/summary', query: {'from': isoDate(_range.start), 'to': isoDate(_range.end)});
      if (mounted) setState(() => (_d = asMap(r['data']), _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_error != null && _d == null) return OnlineOnlyNotice(error: _error!, onRetry: _load);
    if (_d == null) return const LoadingSkeleton();
    final s = context.watch<Session>();
    final cur = s.currency;
    final d = _d!;
    final rows = List<Map>.from(d['rows'] as List)..sort((a, b) => toDouble(b[_sort]).compareTo(toDouble(a[_sort])));
    final t = asMap(d['totals']);
    final hint = Theme.of(context).hintColor;
    final pad = MediaQuery.of(context).size.width < 700 ? 16.0 : 24.0;
    Widget kpi(String label, Object? v, {Color? color, String? sub, IconData? icon}) => KpiBox(
          width: 230,
          child: StatTile(label: label, value: money(toDouble(v), cur), detail: sub, icon: icon, color: color ?? YColors.blue, valueColor: color),
        );
    final cols = [
      ('sales', 'CA HT'),
      ('margin', 'Marge'),
      ('expenses', 'Dépenses'),
      ('result', 'Résultat estimé'),
      ('cash', 'Trésorerie'),
      ('stock_value', 'Stock'),
      ('receivables', 'À encaisser'),
    ];
    final months = List<String>.from(d['months'] as List);
    final series = List<Map>.from(d['series'] as List).where((x) => (x['values'] as List).any((v) => toDouble(v) != 0)).toList();
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(padding: EdgeInsets.fromLTRB(pad, 16, pad, 32), children: [
        Wrap(spacing: 8, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
          ActionChip(
            avatar: const Icon(Icons.calendar_month, size: 18),
            label: Text(tr('du ${dateFr(isoDate(_range.start))} au ${dateFr(isoDate(_range.end))}')),
            onPressed: () async {
              final r = await showDateRangePicker(context: context, firstDate: DateTime(2015), lastDate: DateTime(2100), initialDateRange: _range);
              if (r != null) {
                setState(() => _range = r);
                await _load();
              }
            },
          ),
          if (d['restricted'] == true) Text(tr('Vos agences uniquement'), style: TextStyle(color: hint, fontSize: 12.5)),
        ]),
        const SizedBox(height: 12),
        KpiWrap(children: [
          kpi('Chiffre d\'affaires HT', t['sales'], icon: Icons.payments_outlined),
          kpi('Marge estimée', t['margin'], sub: toDouble(t['margin_base']) > 0 ? 'taux de marge ${number(100 * toDouble(t['margin']) / toDouble(t['margin_base']), decimals: 1)} %' : null),
          kpi('Dépenses', t['expenses'], color: YColors.danger),
          kpi('Résultat estimé', t['result'], color: toDouble(t['result']) >= 0 ? YColors.success : YColors.danger, sub: 'marge − dépenses'),
          kpi('Trésorerie', t['cash'], icon: Icons.account_balance_wallet_outlined),
          kpi('Valeur du stock', t['stock_value'], icon: Icons.inventory_2_outlined),
        ]),
        const SizedBox(height: 12),
        if (rows.any((r) => toDouble(r['sales']) != 0))
          SectionCard(
            title: tr('Chiffre d\'affaires par agence'),
            child: SizedBox(
              height: 36.0 + 26 * rows.where((r) => toDouble(r['sales']) != 0).length,
              child: HBarChart(
                data: ChartData([for (final r in rows.where((r) => toDouble(r['sales']) != 0)) '${r['name']}'], [ChartSeries('CA HT', [for (final r in rows.where((r) => toDouble(r['sales']) != 0)) toDouble(r['sales'])])], 'money'),
                currency: cur,
              ),
            ),
          ),
        const SizedBox(height: 12),
        SectionCard(
          title: tr('Comparatif'),
          trailing: PopupMenuButton<String>(
            tooltip: tr('Trier'),
            initialValue: _sort,
            onSelected: (v) => setState(() => _sort = v),
            itemBuilder: (_) => [for (final c in cols) PopupMenuItem(value: c.$1, child: Text(tr('Trier par ${c.$2.toLowerCase()}')))],
            child: const Padding(padding: EdgeInsets.all(8), child: Icon(Icons.sort)),
          ),
          child: SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: DataTable(
              headingTextStyle: TextStyle(fontWeight: FontWeight.w700, color: hint, fontSize: 12.5),
              columnSpacing: 22,
              columns: [
                DataColumn(label: Text(tr('Agence'))),
                DataColumn(label: Text(tr('Tickets / factures')), numeric: true),
                DataColumn(label: Text(tr('Panier moyen')), numeric: true),
                for (final c in cols) DataColumn(label: Text(tr(c.$2)), numeric: true),
                DataColumn(label: Text(tr('Taux de marge')), numeric: true),
                DataColumn(label: Text(tr('CA couvert')), numeric: true, tooltip: tr('Part du chiffre d\'affaires dont la marge est connue (articles avec prix d\'achat)')),
              ],
              rows: [
                for (final r in rows)
                  DataRow(cells: [
                    DataCell(Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(tr('${r['name']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                      Text(tr([if (r['city'] != null) '${r['city']}', if (r['is_head_office'] == true) 'siège', '${r['users']} utilisateur(s)'].join(' · ')), style: TextStyle(fontSize: 11.5, color: hint)),
                    ])),
                    DataCell(Text(tr('${r['tickets']} / ${r['invoices']}'))),
                    DataCell(Text(tr(money(toDouble(r['basket']), cur)))),
                    for (final c in cols)
                      DataCell(Text(tr(money(toDouble(r[c.$1]), cur)),
                          style: TextStyle(fontWeight: c.$1 == 'result' ? FontWeight.w800 : null, color: c.$1 == 'result' ? (toDouble(r['result']) >= 0 ? YColors.success : YColors.danger) : null))),
                    DataCell(Text(tr(r['margin_rate'] == null ? '—' : '${number(toDouble(r['margin_rate']), decimals: 1)} %'))),
                    DataCell(Text(tr(r['margin_coverage'] == null ? '—' : '${number(toDouble(r['margin_coverage']), decimals: 0)} %'),
                        style: TextStyle(color: toDouble(r['margin_coverage']) < 80 && r['margin_coverage'] != null ? YColors.warning : null))),
                  ]),
              ],
            ),
          ),
        ),
        const SizedBox(height: 6),
        Text(tr('Marge estimée sur les articles qui ont un prix d\'achat (colonne « CA couvert » : en orange, renseignez les prix d\'achat pour un résultat fiable) · dépenses : opérations diverses des caisses et comptes de l\'agence · trésorerie et stock à la fin de la période.'),
            style: TextStyle(fontSize: 12, color: hint)),
        if (months.length > 1 && series.isNotEmpty) ...[
          const SizedBox(height: 12),
          SectionCard(
            title: tr('Évolution mensuelle du chiffre d\'affaires'),
            child: SizedBox(
              height: 260,
              child: CartesianChart(
                data: ChartData([for (final m in months) _monthLabel(m)], [for (final x in series.take(8)) ChartSeries('${x['name']}', [for (final v in x['values'] as List) toDouble(v)])], 'money'),
                currency: cur,
                line: true,
              ),
            ),
          ),
        ],
      ]),
    );
  }

  static const _m = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

  String _monthLabel(String ym) {
    final p = ym.split('-');
    return p.length == 2 ? '${_m[(int.tryParse(p[1]) ?? 1) - 1]} ${p[0].substring(2)}' : ym;
  }
}

// ---------------------------------------------------------------------------
// Paramétrage : agences, dépôts, caisses, équipes
// ---------------------------------------------------------------------------

class _Manage extends StatefulWidget {
  const _Manage();

  @override
  State<_Manage> createState() => _ManageState();
}

class _ManageState extends State<_Manage> {
  List<Map>? _branches;
  List<Map> _users = const [];
  Object? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final api = context.read<Session>().api;
    try {
      final r = await api.get('agences');
      final u = await api.get('users');
      if (mounted) {
        setState(() {
          _branches = List<Map>.from(r['data'] as List);
          _users = List<Map>.from(u['data'] as List).where((x) => x['is_active'] == true).toList();
          _error = null;
        });
      }
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _edit([Map? b]) async {
    final s = context.read<Session>();
    final c = {for (final k in ['name', 'code', 'city', 'address', 'phone']) k: TextEditingController(text: '${b?[k] ?? ''}')};
    int? manager = b?['manager_id'] as int?;
    String? warehouse = b?['warehouse_id'] as String?;
    String? cash = b?['cash_account_id'] as String?;
    var head = b?['is_head_office'] == true;
    var active = b?['active'] != false;
    var createWh = b == null && s.hasModule('stock');
    var createCash = b == null && s.hasModule('tresorerie');
    final users = <int>{...List<int>.from((b?['user_ids'] as List? ?? const []).map((e) => (e as num).toInt()))};
    final warehouses = s.hasModule('stock') ? LocalDb.all('warehouses').where((w) => w['active'] != false).toList() : <Map<String, dynamic>>[];
    final accounts = s.hasModule('tresorerie') ? LocalDb.all('tr_accounts').where((a) => a['active'] != false).toList() : <Map<String, dynamic>>[];
    // valeurs actuelles même si la base locale n'est pas encore synchronisée
    if (warehouse != null && !warehouses.any((w) => w['id'] == warehouse)) warehouses.add({'id': warehouse, 'name': b?['warehouse_name'] ?? 'Dépôt actuel'});
    if (cash != null && !accounts.any((a) => a['id'] == cash)) accounts.add({'id': cash, 'name': b?['cash_account_name'] ?? 'Caisse actuelle'});
    await showFormDialog(context, title: b == null ? 'Nouvelle agence' : 'Modifier l\'agence', builder: (dc) {
      return StatefulBuilder(builder: (dc, set) {
        final hint = Theme.of(dc).hintColor;
        return ListView(padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), shrinkWrap: true, children: [
          Field(label: tr('Nom'), child: TextField(controller: c['name'], decoration: InputDecoration(hintText: tr('Agence du Plateau')))),
          Row(children: [
            Expanded(child: Field(label: tr('Code'), child: TextField(controller: c['code'], decoration: InputDecoration(hintText: tr('PLT'))))),
            const SizedBox(width: 12),
            Expanded(child: Field(label: tr('Ville'), child: TextField(controller: c['city']))),
          ]),
          Field(label: tr('Adresse'), child: TextField(controller: c['address'])),
          Field(label: tr('Téléphone'), child: TextField(controller: c['phone'], keyboardType: TextInputType.phone)),
          Field(
            label: tr('Responsable'),
            child: DropdownButtonFormField<int?>(
              initialValue: manager,
              items: [const DropdownMenuItem<int?>(value: null, child: Text('—')), for (final u in _users) DropdownMenuItem<int?>(value: u['id'] as int, child: Text(tr('${u['name']}')))],
              onChanged: (v) => set(() => manager = v),
            ),
          ),
          if (s.hasModule('stock'))
            Field(
              label: tr('Dépôt de l\'agence'),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                if (!createWh)
                  DropdownButtonFormField<String?>(
                    initialValue: warehouse,
                    items: [DropdownMenuItem<String?>(value: null, child: Text(tr('Aucun (dépôt principal)'))), for (final w in warehouses) DropdownMenuItem<String?>(value: '${w['id']}', child: Text(tr('${w['name']}')))],
                    onChanged: (v) => set(() => warehouse = v),
                  ),
                if (warehouse == null)
                  CheckboxListTile(contentPadding: EdgeInsets.zero, dense: true, value: createWh, onChanged: (v) => set(() => createWh = v ?? false), title: Text(tr('Créer un dépôt pour cette agence'))),
              ]),
            ),
          if (s.hasModule('tresorerie'))
            Field(
              label: tr('Caisse de l\'agence'),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                if (!createCash)
                  DropdownButtonFormField<String?>(
                    initialValue: cash,
                    items: [DropdownMenuItem<String?>(value: null, child: Text(tr('Aucune'))), for (final a in accounts) DropdownMenuItem<String?>(value: '${a['id']}', child: Text(tr('${a['name']}')))],
                    onChanged: (v) => set(() => cash = v),
                  ),
                if (cash == null)
                  CheckboxListTile(contentPadding: EdgeInsets.zero, dense: true, value: createCash, onChanged: (v) => set(() => createCash = v ?? false), title: Text(tr('Créer une caisse pour cette agence'))),
                Text(tr('Les encaissements des ventes de l\'agence y sont enregistrés.'), style: TextStyle(fontSize: 12, color: hint)),
              ]),
            ),
          Field(
            label: tr('Équipe (accès limité à cette agence)'),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Wrap(spacing: 6, runSpacing: 6, children: [
                for (final u in _users)
                  FilterChip(label: Text(tr('${u['name']}')), selected: users.contains(u['id']), onSelected: (v) => set(() => v ? users.add(u['id'] as int) : users.remove(u['id']))),
              ]),
              const SizedBox(height: 4),
              Text(tr('Un utilisateur rattaché à une ou plusieurs agences ne voit que leurs données. Sans agence : accès à tout.'), style: TextStyle(fontSize: 12, color: hint)),
            ]),
          ),
          SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Siège')), value: head, onChanged: (v) => set(() => head = v)),
          if (b != null) SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Agence active')), value: active, onChanged: (v) => set(() => active = v)),
          const SizedBox(height: 8),
          Align(
            alignment: Alignment.centerRight,
            child: FilledButton(
              onPressed: () async {
                final body = {
                  for (final e in c.entries) e.key: e.value.text.trim().isEmpty ? null : e.value.text.trim(),
                  'manager_id': manager,
                  'warehouse_id': createWh ? null : warehouse,
                  'cash_account_id': createCash ? null : cash,
                  'is_head_office': head,
                  'active': active,
                  'user_ids': users.toList(),
                  'create_warehouse': createWh,
                  'create_cash': createCash,
                };
                try {
                  b == null ? await s.api.post('agences', body) : await s.api.put('agences/${b['id']}', body);
                  await s.refreshBootstrap();
                  unawaitedSync(s);
                  if (dc.mounted) Navigator.pop(dc);
                  await _load();
                } catch (e) {
                  if (dc.mounted) toast(dc, errorText(e), error: true);
                }
              },
              child: Text(tr('Enregistrer')),
            ),
          ),
        ]);
      });
    });
  }

  void unawaitedSync(Session s) {
    s.sync.sync().catchError((_) {});
  }

  @override
  Widget build(BuildContext context) {
    if (_error != null && _branches == null) return OnlineOnlyNotice(error: _error!, onRetry: _load);
    if (_branches == null) return const LoadingSkeleton();
    final hint = Theme.of(context).hintColor;
    final pad = MediaQuery.of(context).size.width < 700 ? 16.0 : 24.0;
    final s = context.read<Session>();
    return ListView(padding: EdgeInsets.fromLTRB(pad, 16, pad, 32), children: [
      Row(children: [
        Expanded(child: Text(tr('Chaque agence a son dépôt, sa caisse et son équipe. Les ventes, achats et dépenses saisis dans une agence lui sont rattachés ; l\'agence de travail se choisit en haut du menu.'), style: TextStyle(color: hint))),
        const SizedBox(width: 12),
        FilledButton.icon(onPressed: () => _edit(), icon: const Icon(Icons.add), label: Text(tr('Nouvelle agence'))),
      ]),
      const SizedBox(height: 12),
      if (_branches!.isEmpty) EmptyState(icon: Icons.store_mall_directory_outlined, title: tr('Aucune agence'), message: tr('Créez votre siège et vos agences : un dépôt et une caisse peuvent être créés pour chacune.')),
      for (final b in _branches!)
        Card(
          child: ListTile(
            leading: CircleAvatar(
              backgroundColor: (b['is_head_office'] == true ? YColors.yellow : YColors.blue).withValues(alpha: .15),
              child: Icon(b['is_head_office'] == true ? Icons.apartment : Icons.storefront_outlined, color: b['is_head_office'] == true ? YColors.warning : YColors.blue),
            ),
            title: Row(children: [
              Flexible(child: Text(tr('${b['name']}'), style: const TextStyle(fontWeight: FontWeight.w700))),
              if (b['code'] != null) ...[const SizedBox(width: 8), StatusChip('${b['code']}', color: YColors.blue)],
              if (b['active'] == false) ...[const SizedBox(width: 8), StatusChip(tr('Inactive'), color: YColors.muted)],
            ]),
            subtitle: Text(tr([
              if (b['city'] != null) '${b['city']}',
              if (b['manager_name'] != null) 'responsable ${b['manager_name']}',
              if (b['warehouse_name'] != null) 'dépôt ${b['warehouse_name']}',
              if (b['cash_account_name'] != null) 'caisse ${b['cash_account_name']}',
              '${(b['user_ids'] as List? ?? const []).length} utilisateur(s)',
            ].join(' · '))),
            trailing: Wrap(children: [
              IconButton(tooltip: tr('Modifier'), onPressed: () => _edit(b), icon: const Icon(Icons.edit_outlined)),
              IconButton(
                tooltip: tr('Supprimer'),
                onPressed: () async {
                  if (!await confirm(context, tr('Supprimer ${b['name']} ?'), tr('Une agence qui a des documents ne peut pas être supprimée : désactivez-la.'), ok: tr('Supprimer'))) return;
                  try {
                    await s.api.delete('agences/${b['id']}');
                    await s.refreshBootstrap();
                    await _load();
                  } catch (e) {
                    if (context.mounted) toast(context, errorText(e), error: true);
                  }
                },
                icon: const Icon(Icons.delete_outline),
              ),
            ]),
          ),
        ),
    ]);
  }
}
