import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/local_db.dart';
import '../../core/sync_engine.dart';
import '../../files/save_file.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/filter_bar.dart';
import 'analytique_screen.dart' show AnalyticField, analyticEnabled, anDist;
import '../../i18n/i18n.dart';
import '../../core/json.dart';

const imMethods = {'linear': 'Linéaire', 'degressive': 'Dégressif', 'none': 'Non amortissable'};

List<Map<String, dynamic>> imCategories({bool activeOnly = true}) => LocalDb.hasEntity('im_categories')
    ? (LocalDb.all('im_categories').where((c) => !activeOnly || c['active'] != false).toList()..sort((a, b) => '${a['gl_asset']}'.compareTo('${b['gl_asset']}')))
    : [];

Widget imStatusChip(Map<String, dynamic> a) => switch (a['status']) {
      'running' => StatusChip(tr('En service'), color: YColors.success),
      'disposed' => StatusChip(a['disposal_type'] == 'sale' ? 'Cédée' : 'Mise au rebut', color: YColors.muted),
      _ => StatusChip(tr('Brouillon'), color: YColors.blue),
    };

class ImmobilisationsScreen extends StatelessWidget {
  const ImmobilisationsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    final manage = s.can('immobilisations.manage');
    final tabs = <(String, Widget)>[
      ('Registre', const _AssetsTab()),
      ('Tableau des immobilisations', const _RegisterTab()),
      if (manage) ('Catégories', const _CategoriesTab()),
    ];
    return DefaultTabController(
      length: tabs.length,
      child: Column(children: [
        PageHeader(
          title: tr('Immobilisations'),
          subtitle: tr('Biens durables, amortissements et sorties'),
          actions: [
            if (manage) OutlinedButton.icon(onPressed: () => _settings(context), icon: const Icon(Icons.tune), label: Text(tr('Paramètres'))),
            if (manage) OutlinedButton.icon(onPressed: () => postDepreciation(context), icon: const Icon(Icons.playlist_add_check), label: Text(tr('Comptabiliser les dotations'))),
            if (manage) FilledButton.icon(onPressed: () => editAsset(context), icon: const Icon(Icons.add), label: Text(tr('Nouvelle immobilisation'))),
          ],
        ),
        TabBar(isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [for (final t in tabs) Tab(text: trn(t.$1))]),
        Expanded(child: TabBarView(physics: const NeverScrollableScrollPhysics(), children: [for (final t in tabs) t.$2])),
      ]),
    );
  }

  Future<void> _settings(BuildContext context) async {
    final api = context.read<Session>().api;
    try {
      final s = asMap((await api.get('immobilisations/settings'))['data']);
      if (!context.mounted) return;
      var freq = '${s['frequency']}';
      var auto = s['auto_post'] == true;
      final counter = TextEditingController(text: '${s['counterpart']}');
      final sale = TextEditingController(text: '${s['sale_account']}');
      final ok = await showDialog<bool>(
        context: context,
        builder: (d) => StatefulBuilder(
          builder: (d, set) => YAlertDialog(
            title: Text(tr('Paramètres des immobilisations')),
            content: SizedBox(
              width: 460,
              child: Column(mainAxisSize: MainAxisSize.min, children: [
                DropdownButtonFormField<String>(
                  initialValue: freq,
                  decoration: InputDecoration(labelText: tr('Périodicité des dotations (nouvelles immobilisations)')),
                  items: [DropdownMenuItem(value: 'annual', child: Text(tr('Annuelle (à la clôture)'))), DropdownMenuItem(value: 'monthly', child: Text(tr('Mensuelle')))],
                  onChanged: (v) => set(() => freq = v ?? 'annual'),
                ),
                SwitchListTile(
                  contentPadding: EdgeInsets.zero,
                  title: Text(tr('Comptabiliser automatiquement les dotations échues')),
                  subtitle: Text(tr('Chaque nuit, pour les périodes terminées')),
                  value: auto,
                  onChanged: (v) => set(() => auto = v),
                ),
                TextField(controller: counter, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Compte de contrepartie des acquisitions'), helperText: tr('481 Fournisseurs d\'investissements, 521 Banque…'))),
                TextField(controller: sale, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Compte du prix de cession'), helperText: tr('485 Créances sur cessions, 521 Banque…'))),
              ]),
            ),
            actions: [TextButton(onPressed: () => Navigator.pop(d, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(d, true), child: Text(tr('Enregistrer')))],
          ),
        ),
      );
      if (ok == true) {
        await api.put('immobilisations/settings', {'frequency': freq, 'auto_post': auto, 'counterpart': counter.text.trim(), 'sale_account': sale.text.trim()});
        if (context.mounted) toast(context, tr('Paramètres enregistrés.'));
      }
    } catch (e) {
      if (context.mounted) toast(context, errorText(e), error: true);
    }
  }
}

/// Comptabilise les dotations des périodes terminées jusqu'à une date.
Future<void> postDepreciation(BuildContext context, {String? assetId}) async {
  final n = DateTime.now();
  var until = assetId == null ? DateTime(n.year, n.month, 0) : n; // fin du mois dernier
  final ok = await showDialog<bool>(
    context: context,
    builder: (d) => StatefulBuilder(
      builder: (d, set) => YAlertDialog(
        title: Text(tr('Comptabiliser les dotations')),
        content: SizedBox(
          width: 420,
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(tr(assetId == null
                ? 'Toutes les dotations des périodes terminées au plus tard à cette date sont passées en comptabilité (D 681 / C 28…).'
                : 'Les dotations échues de cette immobilisation sont passées en comptabilité.')),
            const SizedBox(height: 12),
            OutlinedButton.icon(
              onPressed: () async {
                final x = await pickDate(d, initialDate: until, firstDate: DateTime(2000), lastDate: DateTime(2100));
                if (x != null) set(() => until = x);
              },
              icon: const Icon(Icons.event),
              label: Text(tr('Jusqu\'au ${dateFr(isoDate(until))}')),
            ),
            const SizedBox(height: 8),
            Wrap(spacing: 8, children: [
              ActionChip(label: Text(tr('31/12/${n.year - 1}')), onPressed: () => set(() => until = DateTime(n.year - 1, 12, 31))),
              ActionChip(label: Text(tr('Fin du mois dernier')), onPressed: () => set(() => until = DateTime(n.year, n.month, 0))),
              ActionChip(label: Text(tr('31/12/${n.year}')), onPressed: () => set(() => until = DateTime(n.year, 12, 31))),
            ]),
          ]),
        ),
        actions: [TextButton(onPressed: () => Navigator.pop(d, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(d, true), child: Text(tr('Comptabiliser')))],
      ),
    ),
  );
  if (ok != true || !context.mounted) return;
  try {
    final r = (await context.read<Session>().api.post('immobilisations/depreciate', {'until': isoDate(until), 'asset_id': ?assetId}))['data'] as Map;
    if (!context.mounted) return;
    await context.read<SyncEngine>().sync();
    if (context.mounted) {
      toast(context, r['count'] == 0 ? 'Aucune dotation à comptabiliser à cette date.' : '${r['count']} dotation(s) comptabilisée(s) : ${money(toDouble(r['total']), context.read<Session>().currency)}.');
    }
  } catch (e) {
    if (context.mounted) toast(context, errorText(e), error: true);
  }
}

// ---------------------------------------------------------------------------
// Registre (hors ligne)
// ---------------------------------------------------------------------------

class _AssetsTab extends StatefulWidget {
  const _AssetsTab();

  @override
  State<_AssetsTab> createState() => _AssetsTabState();
}

class _AssetsTabState extends State<_AssetsTab> {
  String _status = 'running';
  String _q = '';
  Map<String, String?> _f = {};
  FilterPeriod? _period;

  @override
  Widget build(BuildContext context) {
    final cur = context.watch<Session>().currency;
    final pad = MediaQuery.of(context).size.width < 700 ? 16.0 : 24.0;
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('im_assets'),
      builder: (context, _, _) {
        final all = LocalDb.all('im_assets');
        bool base(Map<String, dynamic> a) =>
            (_q.isEmpty || '${a['code'] ?? ''} ${a['name']} ${a['serial_number'] ?? ''} ${a['location'] ?? ''}'.toLowerCase().contains(_q)) &&
            (_f['category'] == null || a['category_id'] == _f['category']) &&
            (_f['method'] == null || '${a['method'] ?? 'linear'}' == _f['method']) &&
            (_f['location'] == null || '${a['location'] ?? ''}' == _f['location']) &&
            (_f['employee'] == null || a['employee_id'] == _f['employee']) &&
            (_f['partner'] == null || a['partner_id'] == _f['partner']) &&
            (_period == null || _period!.contains(a['acquisition_date']));
        bool st(Map<String, dynamic> a, String k) => k.isEmpty || a['status'] == k;
        final items = all.where((a) => base(a) && st(a, _status)).toList()
          ..sort((a, b) => '${a['code'] ?? 'zzz'}${a['name']}'.compareTo('${b['code'] ?? 'zzz'}${b['name']}'));
        final hasEmp = LocalDb.hasEntity('rh_employees');
        final hasPartners = LocalDb.hasEntity('partners');
        final running = all.where((a) => a['status'] == 'running');
        final gross = running.fold<double>(0, (s, a) => s + toDouble(a['value']));
        final nbv = running.fold<double>(0, (s, a) => s + toDouble(a['nbv'] ?? a['value']));
        final due = running.where((a) => a['next_period_end'] != null && '${a['next_period_end']}'.compareTo(isoDate(DateTime.now())) < 0).length;
        return Column(children: [
          Padding(
            padding: EdgeInsets.fromLTRB(pad, 12, pad, 8),
            child: Align(
              alignment: Alignment.centerLeft,
              child: KpiWrap(children: [
                _Kpi('Biens en service', '${running.length}'),
                _Kpi('Valeur brute', money(gross, cur)),
                _Kpi('Valeur nette comptable', money(nbv, cur), color: YColors.night),
                _Kpi('Amortis', gross > 0 ? '${number((gross - nbv) / gross * 100, decimals: 0)} %' : '—'),
                if (due > 0) _Kpi('Dotations en retard', '$due bien(s)', color: YColors.warning),
              ]),
            ),
          ),
          FilterBar(
            padding: EdgeInsets.fromLTRB(pad, 4, pad, 8),
            onSearch: (v) => setState(() => _q = v.toLowerCase()),
            search: _q,
            searchHint: 'Code, désignation, n° de série, emplacement…',
            statuses: const {'running': 'En service', 'draft': 'Brouillons', 'disposed': 'Sorties', '': 'Toutes'},
            status: _status,
            onStatus: (v) => setState(() => _status = v),
            statusCounts: {for (final k in ['running', 'draft', 'disposed', '']) k: all.where((a) => base(a) && st(a, k)).length},
            filters: [
              FilterSpec('category', 'Catégorie', {for (final c in imCategories(activeOnly: false)) '${c['id']}': '${c['name']}'}, icon: Icons.category_outlined),
              const FilterSpec('method', 'Méthode', imMethods, icon: Icons.trending_down),
              FilterSpec('location', 'Emplacement', filterOptions(all, 'location'), icon: Icons.place_outlined),
              if (hasEmp)
                FilterSpec('employee', 'Responsable', filterOptions(all, 'employee_id', label: (a) {
                  final e = LocalDb.get('rh_employees', a['employee_id'] as String?);
                  return e == null ? '—' : '${e['last_name'] ?? ''} ${e['first_name'] ?? ''}'.trim();
                }), icon: Icons.person_outline),
              if (hasPartners)
                FilterSpec('partner', 'Fournisseur', filterOptions(all, 'partner_id', label: (a) => '${LocalDb.get('partners', a['partner_id'] as String?)?['name'] ?? '—'}'), icon: Icons.storefront_outlined),
            ],
            values: _f,
            onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
            showPeriod: true,
            period: _period,
            onPeriod: (p) => setState(() => _period = p),
            count: items.length,
            countLabel: 'immobilisation',
            onReset: () => setState(() => (_q = '', _status = 'running', _f = {}, _period = null)),
          ),
          Expanded(
            child: items.isEmpty
                ? EmptyState(icon: Icons.domain, title: tr('Aucune immobilisation'), message: tr('Véhicules, matériel, mobilier, ordinateurs, logiciels : enregistrez-les ou créez-les depuis une facture fournisseur.'))
                : ResponsiveList<Map<String, dynamic>>(
                    items: items,
                    columns: const ['Code', 'Désignation', 'Catégorie', 'Valeur', 'VNC', 'Statut'],
                    onTap: (a) => Navigator.of(context).push(MaterialPageRoute(builder: (_) => AssetDetail(id: '${a['id']}'))),
                    cells: (a) => [
                      Text(tr('${a['code'] ?? '—'}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                      Text(tr('${a['name']}')),
                      Text(tr('${LocalDb.get('im_categories', a['category_id'] as String?)?['name'] ?? '—'}')),
                      Text(tr(money(toDouble(a['value']), cur))),
                      Text(tr(a['status'] == 'draft' ? '—' : money(toDouble(a['nbv']), cur))),
                      imStatusChip(a),
                    ],
                    mobileTile: (a) => Row(children: [
                      Expanded(
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Text(tr('${a['code'] != null ? '${a['code']} · ' : ''}${a['name']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                          Text(tr('${money(toDouble(a['value']), cur)}${a['status'] == 'running' ? ' · VNC ${money(toDouble(a['nbv']), cur)}' : ''}'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 13)),
                        ]),
                      ),
                      imStatusChip(a),
                    ]),
                  ),
          ),
        ]);
      },
    );
  }
}

class _Kpi extends StatelessWidget {
  const _Kpi(this.label, this.value, {this.color});

  final String label;
  final String value;
  final Color? color;

  @override
  Widget build(BuildContext context) => KpiBox(
        width: 230,
        child: StatTile(label: label, value: value, color: color ?? YColors.blue, valueColor: color),
      );
}

// ---------------------------------------------------------------------------
// Formulaire
// ---------------------------------------------------------------------------

Future<void> editAsset(BuildContext context, [Map<String, dynamic>? asset]) =>
    showFormDialog(context, title: asset == null ? 'Nouvelle immobilisation' : 'Immobilisation ${asset['code'] ?? ''}', builder: (_) => _AssetForm(asset: asset));

class _AssetForm extends StatefulWidget {
  const _AssetForm({this.asset});
  final Map<String, dynamic>? asset;

  @override
  State<_AssetForm> createState() => _AssetFormState();
}

class _AssetFormState extends State<_AssetForm> {
  late final a = widget.asset ?? <String, dynamic>{};
  late final _name = TextEditingController(text: '${a['name'] ?? ''}');
  late final _value = TextEditingController(text: a['value'] != null ? number(toDouble(a['value'])).replaceAll(RegExp(r'\s'), '') : '');
  late final _salvage = TextEditingController(text: toDouble(a['salvage_value']) > 0 ? number(toDouble(a['salvage_value'])).replaceAll(RegExp(r'\s'), '') : '');
  late final _years = TextEditingController(text: '${a['years'] ?? ''}');
  late final _coef = TextEditingController(text: a['coefficient'] != null ? '${a['coefficient']}' : '');
  late final _serial = TextEditingController(text: '${a['serial_number'] ?? ''}');
  late final _location = TextEditingController(text: '${a['location'] ?? ''}');
  late final _account = TextEditingController(text: '${a['acquisition_account'] ?? ''}');
  late final _notes = TextEditingController(text: '${a['notes'] ?? ''}');
  late String? _category = a['category_id'] as String?;
  late String _method = '${a['method'] ?? 'linear'}';
  late String _frequency = '${a['frequency'] ?? 'annual'}';
  late DateTime? _acq = DateTime.tryParse('${a['acquisition_date'] ?? ''}') ?? DateTime.now();
  late DateTime? _service = DateTime.tryParse('${a['in_service_date'] ?? ''}');
  late DateTime? _opening = DateTime.tryParse('${a['opening_date'] ?? ''}');
  late String? _employee = a['employee_id'] as String?;
  late String? _partner = a['partner_id'] as String?;
  late bool _postAcq = a['post_acquisition'] == true || widget.asset == null;
  late Map<String, double> _analytic = anDist(a['analytic']);
  bool _reprise = false;
  List<Map<String, dynamic>>? _preview;
  bool _busy = false;

  bool get _draft => (a['status'] ?? 'draft') == 'draft';

  @override
  void initState() {
    super.initState();
    _reprise = _opening != null;
  }

  void _pickCategory(String? id) {
    final c = LocalDb.get('im_categories', id);
    setState(() {
      _category = id;
      if (c != null) {
        _method = '${c['method']}';
        _years.text = '${c['years']}';
        _coef.text = c['coefficient'] != null ? '${c['coefficient']}' : '';
      }
      _preview = null;
    });
  }

  Map<String, dynamic> _payload() => {
        'id': a['id'] ?? newId(),
        'name': _name.text.trim(),
        if (_draft) ...{
          'category_id': _category,
          'value': toDouble(_value.text.replaceAll(RegExp(r'\s'), '').replaceAll(',', '.')),
          'salvage_value': toDouble(_salvage.text.replaceAll(RegExp(r'\s'), '').replaceAll(',', '.')),
          'method': _method,
          'years': int.tryParse(_years.text) ?? 5,
          'coefficient': _method == 'degressive' && _coef.text.trim().isNotEmpty ? toDouble(_coef.text.replaceAll(',', '.')) : null,
          'frequency': _frequency,
          'acquisition_date': _acq == null ? null : isoDate(_acq!),
          'in_service_date': _service == null ? null : isoDate(_service!),
          'opening_date': _reprise && _opening != null ? isoDate(_opening!) : null,
          'partner_id': _partner,
          'post_acquisition': _postAcq && !_reprise,
          'acquisition_account': _account.text.trim().isEmpty ? null : _account.text.trim(),
        },
        'serial_number': _serial.text.trim().isEmpty ? null : _serial.text.trim(),
        'location': _location.text.trim().isEmpty ? null : _location.text.trim(),
        'employee_id': _employee,
        if (analyticEnabled(context.read<Session>())) 'analytic': _analytic.isEmpty ? null : _analytic,
        'notes': _notes.text.trim().isEmpty ? null : _notes.text.trim(),
      };

  Future<void> _simulate() async {
    final p = _payload();
    if (toDouble(p['value']) <= 0) return toast(context, tr('Indiquez la valeur.'), error: true);
    try {
      final r = await context.read<Session>().api.post('immobilisations/preview', {
        'value': p['value'],
        'salvage_value': p['salvage_value'],
        'method': p['method'],
        'years': p['years'],
        'coefficient': p['coefficient'],
        'in_service_date': p['in_service_date'] ?? p['acquisition_date'],
        'acquisition_date': p['acquisition_date'],
        'frequency': p['frequency'],
      });
      if (mounted) setState(() => _preview = [for (final l in r['data'] as List) asMap(l)]);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _save({bool activate = false}) async {
    if (_name.text.trim().isEmpty) return toast(context, tr('Indiquez la désignation.'), error: true);
    if (activate && !await confirm(context, tr('Mettre en service ?'), tr('Le plan d\'amortissement sera figé${_postAcq && !_reprise ? ' et l\'acquisition comptabilisée' : ''}. Seuls le nom, l\'emplacement, le responsable et la ventilation resteront modifiables.'))) return;
    if (!mounted) return;
    setState(() => _busy = true);
    try {
      final saved = await context.read<SyncEngine>().save('im_assets', {..._payload(), if (activate) 'status': 'running'});
      if (!mounted) return;
      Navigator.pop(context);
      toast(context, activate ? (saved['_local'] == true ? 'Mise en service envoyée : le plan sera calculé à la synchronisation.' : 'Immobilisation mise en service.') : 'Enregistré.');
    } catch (e) {
      if (mounted) {
        setState(() => _busy = false);
        toast(context, errorText(e), error: true);
      }
    }
  }

  Widget _date(String label, DateTime? v, ValueChanged<DateTime> on, {bool enabled = true}) => Field(
        label: label,
        child: OutlinedButton.icon(
          onPressed: !enabled
              ? null
              : () async {
                  final d = await pickDate(context, initialDate: v ?? DateTime.now(), firstDate: DateTime(1990), lastDate: DateTime(2100));
                  if (d != null) {
                    setState(() {
                      on(d);
                      _preview = null;
                    });
                  }
                },
          icon: const Icon(Icons.event, size: 18),
          label: Text(tr(v == null ? 'Choisir' : dateFr(isoDate(v)))),
        ),
      );

  @override
  Widget build(BuildContext context) {
    final s = context.read<Session>();
    final cur = s.currency;
    final d = _draft;
    Widget row(List<Widget> children) => Row(crossAxisAlignment: CrossAxisAlignment.start, children: [for (var i = 0; i < children.length; i++) ...[if (i > 0) const SizedBox(width: 12), Expanded(child: children[i])]]);
    final employees = s.hasModule('rh') && LocalDb.hasEntity('rh_employees') ? (LocalDb.all('rh_employees').where((e) => e['active'] != false).toList()..sort((x, y) => '${x['last_name']}'.compareTo('${y['last_name']}'))) : <Map<String, dynamic>>[];
    final suppliers = LocalDb.hasEntity('partners') ? (LocalDb.all('partners').where((p) => p['type'] == 'supplier' || p['type'] == 'both').toList()..sort((x, y) => '${x['name']}'.compareTo('${y['name']}'))) : <Map<String, dynamic>>[];
    return Column(children: [
      Expanded(
        child: ListView(padding: const EdgeInsets.fromLTRB(24, 8, 24, 8), children: [
          if (!d)
            Container(
              margin: const EdgeInsets.only(bottom: 12),
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(color: YColors.blue.withValues(alpha: 0.08), borderRadius: BorderRadius.circular(8)),
              child: Text(tr('En service : le plan d\'amortissement est figé. Pour corriger la valeur ou la durée, enregistrez une sortie puis recréez le bien.')),
            ),
          Field(label: tr('Désignation'), child: TextField(controller: _name, decoration: InputDecoration(hintText: tr('Toyota Hilux AB-1234-CI, climatiseur 2 CV salle de réunion…')))),
          Field(
            label: tr('Catégorie'),
            child: DropdownButtonFormField<String>(
              initialValue: imCategories(activeOnly: false).any((c) => c['id'] == _category) ? _category : null,
              isExpanded: true,
              items: [for (final c in imCategories()) DropdownMenuItem(value: '${c['id']}', child: Text(tr('${c['gl_asset']} · ${c['name']}')))],
              onChanged: d ? _pickCategory : null,
            ),
          ),
          row([
            Field(label: tr('Valeur d\'acquisition HT'), child: TextField(controller: _value, enabled: d, keyboardType: TextInputType.number, onChanged: (_) => setState(() => _preview = null))),
            Field(label: tr('Valeur résiduelle'), child: TextField(controller: _salvage, enabled: d, keyboardType: TextInputType.number, decoration: const InputDecoration(hintText: '0'))),
          ]),
          row([
            _date('Acquisition', _acq, (v) => _acq = v, enabled: d),
            _date('Mise en service', _service, (v) => _service = v, enabled: d),
          ]),
          row([
            Field(
              label: tr('Méthode'),
              child: DropdownButtonFormField<String>(
                initialValue: _method,
                items: [for (final e in imMethods.entries) DropdownMenuItem(value: e.key, child: Text(tr(e.value)))],
                onChanged: d ? (v) => setState(() => (_method = v ?? 'linear', _preview = null)) : null,
              ),
            ),
            Field(label: tr('Durée (années)'), child: TextField(controller: _years, enabled: d && _method != 'none', keyboardType: TextInputType.number, onChanged: (_) => setState(() => _preview = null))),
          ]),
          if (_method == 'degressive')
            Field(label: tr('Coefficient'), child: TextField(controller: _coef, enabled: d, keyboardType: const TextInputType.numberWithOptions(decimal: true), decoration: InputDecoration(hintText: tr('Vide : 1,5 (3-4 ans), 2 (5-6 ans), 2,5 au-delà')))),
          Field(
            label: tr('Dotations'),
            child: SegmentedButton<String>(
              segments: [ButtonSegment(value: 'annual', label: Text(tr('Annuelles'))), ButtonSegment(value: 'monthly', label: Text(tr('Mensuelles')))],
              selected: {_frequency},
              onSelectionChanged: d ? (v) => setState(() => (_frequency = v.first, _preview = null)) : null,
            ),
          ),
          if (d) ...[
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              title: Text(tr('Reprise d\'un registre existant')),
              subtitle: Text(tr('Les amortissements jusqu\'à la date de reprise sont considérés comme déjà comptabilisés')),
              value: _reprise,
              onChanged: (v) => setState(() => _reprise = v),
            ),
            if (_reprise) _date('Date de reprise (ex. 31/12 de l\'an dernier)', _opening, (v) => _opening = v),
            if (!_reprise)
              SwitchListTile(
                contentPadding: EdgeInsets.zero,
                title: Text(tr('Comptabiliser l\'acquisition à la mise en service')),
                subtitle: Text(tr(_account.text.isEmpty ? 'Débit du compte d\'immobilisation, crédit 481 (ou le compte ci-dessous)' : 'Contrepartie : ${_account.text}')),
                value: _postAcq,
                onChanged: (v) => setState(() => _postAcq = v),
              ),
            if (!_reprise && _postAcq) Field(label: tr('Compte de contrepartie (facultatif)'), child: TextField(controller: _account, keyboardType: TextInputType.number, decoration: InputDecoration(hintText: tr('481 par défaut ; 601/605 pour reclasser un achat')), onChanged: (_) => setState(() {}))),
            Align(
              alignment: Alignment.centerLeft,
              child: OutlinedButton.icon(onPressed: _simulate, icon: const Icon(Icons.table_chart_outlined), label: Text(tr('Voir le plan d\'amortissement'))),
            ),
            if (_preview != null)
              Container(
                margin: const EdgeInsets.only(top: 8, bottom: 12),
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(border: Border.all(color: YColors.line), borderRadius: BorderRadius.circular(8)),
                child: Column(children: [
                  for (final l in _preview!.take(60))
                    Padding(
                      padding: const EdgeInsets.symmetric(vertical: 2),
                      child: Row(children: [
                        Expanded(child: Text(tr('${dateFr(l['period_start'])} – ${dateFr(l['period_end'])}'), style: const TextStyle(fontSize: 12))),
                        SizedBox(width: 110, child: Text(tr(money(toDouble(l['amount']), cur)), textAlign: TextAlign.right, style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600))),
                        SizedBox(width: 120, child: Text(tr('VNC ${money(toDouble(l['nbv']), cur)}'), textAlign: TextAlign.right, style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor))),
                      ]),
                    ),
                  if (_preview!.isEmpty) Text(tr('Aucun amortissement.')),
                ]),
              ),
          ],
          const SizedBox(height: 8),
          row([
            Field(label: tr('N° de série / immatriculation'), child: TextField(controller: _serial)),
            Field(label: tr('Emplacement'), child: TextField(controller: _location, decoration: InputDecoration(hintText: tr('Siège, agence, véhicule…')))),
          ]),
          if (employees.isNotEmpty)
            Field(
              label: tr('Responsable / utilisateur'),
              child: DropdownButtonFormField<String?>(
                initialValue: employees.any((e) => e['id'] == _employee) ? _employee : null,
                isExpanded: true,
                items: [const DropdownMenuItem(value: null, child: Text('—')), for (final e in employees) DropdownMenuItem(value: '${e['id']}', child: Text(tr('${e['last_name']} ${e['first_name']}')))],
                onChanged: (v) => setState(() => _employee = v),
              ),
            ),
          if (d && suppliers.isNotEmpty)
            Field(
              label: tr('Fournisseur'),
              child: DropdownButtonFormField<String?>(
                initialValue: suppliers.any((p) => p['id'] == _partner) ? _partner : null,
                isExpanded: true,
                items: [const DropdownMenuItem(value: null, child: Text('—')), for (final p in suppliers) DropdownMenuItem(value: '${p['id']}', child: Text(tr('${p['name']}'), overflow: TextOverflow.ellipsis))],
                onChanged: (v) => setState(() => _partner = v),
              ),
            ),
          if (analyticEnabled(s)) Field(label: tr('Analytique (dotations)'), child: AnalyticField(value: _analytic, onChanged: (v) => setState(() => _analytic = v))),
          Field(label: tr('Notes'), child: TextField(controller: _notes, maxLines: 2)),
        ]),
      ),
      Padding(
        padding: const EdgeInsets.fromLTRB(24, 8, 24, 16),
        child: Row(mainAxisAlignment: MainAxisAlignment.end, children: [
          TextButton(onPressed: _busy ? null : () => Navigator.pop(context), child: Text(tr('Annuler'))),
          const SizedBox(width: 8),
          OutlinedButton(onPressed: _busy ? null : _save, child: Text(tr('Enregistrer'))),
          if (d) ...[
            const SizedBox(width: 8),
            FilledButton.icon(onPressed: _busy ? null : () => _save(activate: true), icon: const Icon(Icons.play_arrow), label: Text(tr('Mettre en service'))),
          ],
        ]),
      ),
    ]);
  }
}

// ---------------------------------------------------------------------------
// Fiche et plan d'amortissement
// ---------------------------------------------------------------------------

class AssetDetail extends StatefulWidget {
  const AssetDetail({super.key, required this.id});
  final String id;

  @override
  State<AssetDetail> createState() => _AssetDetailState();
}

class _AssetDetailState extends State<AssetDetail> {
  Map<String, dynamic>? _a;
  Object? _error;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('immobilisations/assets/${widget.id}');
      if (mounted) setState(() => (_a = asMap(r['data']), _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _act(Future<dynamic> Function() fn, String ok) async {
    setState(() => _busy = true);
    try {
      final r = await fn();
      if (r is Map && r['data'] is Map) setState(() => _a = asMap(r['data']));
      if (mounted) {
        await context.read<SyncEngine>().sync();
        if (mounted) toast(context, ok);
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _dispose() async {
    var type = 'sale';
    var date = DateTime.now();
    final amount = TextEditingController();
    final note = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (d) => StatefulBuilder(
        builder: (d, set) => YAlertDialog(
          title: Text(tr('Sortie de l\'immobilisation')),
          content: SizedBox(
            width: 440,
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              SegmentedButton<String>(
                segments: [ButtonSegment(value: 'sale', label: Text(tr('Cession (vente)'))), ButtonSegment(value: 'scrap', label: Text(tr('Mise au rebut')))],
                selected: {type},
                onSelectionChanged: (v) => set(() => type = v.first),
              ),
              const SizedBox(height: 10),
              OutlinedButton.icon(
                onPressed: () async {
                  final x = await pickDate(d, initialDate: date, firstDate: DateTime(2000), lastDate: DateTime(2100));
                  if (x != null) set(() => date = x);
                },
                icon: const Icon(Icons.event),
                label: Text(tr('Date de sortie : ${dateFr(isoDate(date))}')),
              ),
              if (type == 'sale') TextField(controller: amount, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Prix de cession HT'))),
              TextField(controller: note, decoration: InputDecoration(labelText: tr('Motif / acquéreur'))),
              const SizedBox(height: 8),
              Text(tr('La dotation jusqu\'à la date de sortie, la sortie de l\'actif (valeur nette en 81) et le prix de cession (82) sont comptabilisés.'), style: TextStyle(fontSize: 12, color: Theme.of(d).hintColor)),
            ]),
          ),
          actions: [TextButton(onPressed: () => Navigator.pop(d, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(d, true), child: Text(tr('Enregistrer la sortie')))],
        ),
      ),
    );
    if (ok != true || !mounted) return;
    await _act(
      () => context.read<Session>().api.post('immobilisations/assets/${widget.id}/dispose', {
        'date': isoDate(date),
        'type': type,
        'amount': toDouble(amount.text.replaceAll(RegExp(r'\s'), '')),
        'note': note.text.trim().isEmpty ? null : note.text.trim(),
      }),
      'Sortie enregistrée.',
    );
  }

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    final cur = s.currency;
    final manage = s.can('immobilisations.manage');
    if (_a == null) {
      return Scaffold(appBar: AppBar(), body: _error != null ? OnlineOnlyNotice(error: _error!, onRetry: _load) : const Center(child: CircularProgressIndicator()));
    }
    final a = _a!;
    final cat = LocalDb.get('im_categories', a['category_id'] as String?);
    final lines = [for (final l in a['lines'] as List) asMap(l)];
    final today = isoDate(DateTime.now());
    final due = lines.where((l) => l['posted_at'] == null && '${l['period_end']}'.compareTo(today) <= 0).length;
    final api = s.api;
    final value = toDouble(a['value']);
    return Scaffold(
      appBar: AppBar(
        title: Row(children: [Flexible(child: Text(tr('${a['code'] ?? ''} ${a['name']}'), overflow: TextOverflow.ellipsis)), const SizedBox(width: 10), imStatusChip(a)]),
        actions: [
          if (manage && a['status'] == 'running' && due > 0)
            FilledButton.icon(onPressed: _busy ? null : () async {
              await postDepreciation(context, assetId: widget.id);
              _load();
            }, icon: const Icon(Icons.playlist_add_check), label: Text(tr('Comptabiliser ($due)'))),
          if (manage)
            PopupMenuButton<String>(
              onSelected: (v) async {
                if (v == 'edit') {
                  await editAsset(context, LocalDb.get('im_assets', widget.id) ?? a);
                  _load();
                }
                if (v == 'dispose') _dispose();
                if (v == 'unpost' && context.mounted && await confirm(context, tr('Annuler la dernière dotation ?'), tr('L\'écriture est supprimée (ou contre-passée si la période est verrouillée).'))) {
                  _act(() => api.post('immobilisations/assets/${widget.id}/unpost'), 'Dotation annulée.');
                }
                if (v == 'cancel' && context.mounted && await confirm(context, tr('Annuler la sortie ?'), tr('Les écritures de sortie sont contre-passées et le bien revient en service.'))) {
                  _act(() => api.post('immobilisations/assets/${widget.id}/cancel-disposal'), 'Sortie annulée.');
                }
              },
              itemBuilder: (_) => [
                if (a['status'] != 'disposed') PopupMenuItem(value: 'edit', child: Text(tr('Modifier'))),
                if (a['status'] == 'running') PopupMenuItem(value: 'dispose', child: Text(tr('Céder / mettre au rebut'))),
                if (a['status'] == 'running' && lines.any((l) => l['posted_at'] != null && l['kind'] == 'normal')) PopupMenuItem(value: 'unpost', child: Text(tr('Annuler la dernière dotation'))),
                if (a['status'] == 'disposed') PopupMenuItem(value: 'cancel', child: Text(tr('Annuler la sortie'))),
              ],
            ),
          const SizedBox(width: 8),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(padding: const EdgeInsets.all(16), children: [
          KpiWrap(children: [
            _Kpi('Valeur d\'acquisition', money(value, cur)),
            _Kpi('Amortissements', money(toDouble(a['depreciated']), cur)),
            _Kpi('Valeur nette', money(toDouble(a['nbv']), cur), color: YColors.night),
            if (a['status'] == 'disposed') _Kpi(a['disposal_type'] == 'sale' ? 'Cédée le ${dateFr(a['disposal_date'])}' : 'Rebut le ${dateFr(a['disposal_date'])}', a['disposal_type'] == 'sale' ? money(toDouble(a['disposal_amount']), cur) : '—'),
          ]),
          const SizedBox(height: 12),
          SectionCard(
            title: tr('Fiche'),
            child: Wrap(spacing: 32, runSpacing: 10, children: [
              _info('Catégorie', cat == null ? '—' : '${cat['gl_asset']} · ${cat['name']}'),
              _info('Méthode', '${imMethods[a['method']] ?? a['method']}${a['method'] != 'none' ? ' sur ${a['years']} an(s)' : ''}${a['method'] == 'degressive' && a['coefficient'] != null ? ' (coef. ${a['coefficient']})' : ''}'),
              _info('Dotations', a['frequency'] == 'monthly' ? 'Mensuelles' : 'Annuelles'),
              _info('Acquisition', dateFr(a['acquisition_date'])),
              _info('Mise en service', dateFr(a['in_service_date'])),
              if (toDouble(a['salvage_value']) > 0) _info('Valeur résiduelle', money(toDouble(a['salvage_value']), cur)),
              if (a['serial_number'] != null) _info('N° de série', '${a['serial_number']}'),
              if (a['location'] != null) _info('Emplacement', '${a['location']}'),
              if (a['employee_id'] != null && LocalDb.hasEntity('rh_employees')) _info('Responsable', '${LocalDb.get('rh_employees', a['employee_id'] as String?)?['last_name'] ?? ''} ${LocalDb.get('rh_employees', a['employee_id'] as String?)?['first_name'] ?? ''}'),
              if (a['partner_id'] != null) _info('Fournisseur', '${LocalDb.get('partners', a['partner_id'] as String?)?['name'] ?? '—'}'),
              if (a['opening_date'] != null) _info('Reprise au', dateFr(a['opening_date'])),
              if (a['notes'] != null) _info('Notes', '${a['notes']}'),
            ]),
          ),
          const SizedBox(height: 12),
          SectionCard(
            title: tr('Plan d\'amortissement'),
            child: lines.isEmpty
                ? Text(tr(a['status'] == 'draft' ? 'Mettez le bien en service pour calculer son plan.' : 'Bien non amortissable.'), style: TextStyle(color: Theme.of(context).hintColor))
                : Column(children: [
                    Padding(
                      padding: const EdgeInsets.only(bottom: 6),
                      child: DefaultTextStyle.merge(
                        style: TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: Theme.of(context).hintColor),
                        child: Row(children: [
                          Expanded(child: Text(tr('Période'))),
                          SizedBox(width: 120, child: Text(tr('Dotation'), textAlign: TextAlign.right)),
                          SizedBox(width: 130, child: Text(tr('Cumul'), textAlign: TextAlign.right)),
                          SizedBox(width: 130, child: Text(tr('VNC'), textAlign: TextAlign.right)),
                          SizedBox(width: 110, child: Text(tr('État'), textAlign: TextAlign.right)),
                        ]),
                      ),
                    ),
                    for (final l in lines)
                      Container(
                        padding: const EdgeInsets.symmetric(vertical: 8),
                        decoration: const BoxDecoration(border: Border(top: BorderSide(color: YColors.line))),
                        child: Row(children: [
                          Expanded(child: Text(tr('${dateFr(l['period_start'])} – ${dateFr(l['period_end'])}'))),
                          SizedBox(width: 120, child: Text(tr(money(toDouble(l['amount']), cur)), textAlign: TextAlign.right, style: const TextStyle(fontWeight: FontWeight.w600))),
                          SizedBox(width: 130, child: Text(tr(money(toDouble(l['cumulated']), cur)), textAlign: TextAlign.right)),
                          SizedBox(width: 130, child: Text(tr(money(toDouble(l['nbv']), cur)), textAlign: TextAlign.right)),
                          SizedBox(
                            width: 110,
                            child: Align(
                              alignment: Alignment.centerRight,
                              child: l['kind'] == 'opening'
                                  ? StatusChip(tr('Reprise'), color: YColors.muted)
                                  : l['posted_at'] != null
                                      ? StatusChip(tr('Comptabilisée'), color: YColors.success)
                                      : ('${l['period_end']}'.compareTo(today) <= 0 ? StatusChip(tr('À passer'), color: YColors.warning) : StatusChip(tr('Prévue'))),
                            ),
                          ),
                        ]),
                      ),
                  ]),
          ),
        ]),
      ),
    );
  }

  Widget _info(String label, String value) => SizedBox(
        width: 240,
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(tr(label), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
          const SizedBox(height: 2),
          Text(tr(value), style: const TextStyle(fontWeight: FontWeight.w600)),
        ]),
      );
}

// ---------------------------------------------------------------------------
// Tableau des immobilisations
// ---------------------------------------------------------------------------

class _RegisterTab extends StatefulWidget {
  const _RegisterTab();

  @override
  State<_RegisterTab> createState() => _RegisterTabState();
}

class _RegisterTabState extends State<_RegisterTab> {
  int _year = DateTime.now().year;
  Map<String, dynamic>? _d;
  Object? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('immobilisations/register', query: {'year': _year});
      if (mounted) setState(() => (_d = asMap(r['data']), _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _export() async {
    try {
      final bytes = await context.read<Session>().api.download('immobilisations/register/export', query: {'year': _year});
      final where = await saveFile('tableau_immobilisations_$_year.xlsx', bytes, mime: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
      if (mounted) toast(context, tr('Tableau enregistré : $where'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final cur = context.watch<Session>().currency;
    if (_d == null) return _error != null ? OnlineOnlyNotice(error: _error!, onRetry: _load) : const LoadingSkeleton();
    final t = asMap(_d!['totals']);
    final cats = [for (final c in _d!['categories'] as List) asMap(c)];
    final assets = [for (final a in _d!['assets'] as List) asMap(a)];
    const cols = [('gross_start', 'Brut début'), ('additions', 'Acquisitions'), ('disposals', 'Sorties'), ('gross_end', 'Brut fin'), ('dep_start', 'Amort. début'), ('dep_year', 'Dotations'), ('dep_reversal', 'Reprises'), ('dep_end', 'Amort. fin'), ('nbv_end', 'VNC fin')];
    DataRow row(String label, Map<String, dynamic> m, {bool bold = false, String? sub}) => DataRow(cells: [
          DataCell(Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(tr(label), style: TextStyle(fontWeight: bold ? FontWeight.w800 : FontWeight.w600)),
            if (sub != null) Text(tr(sub), style: TextStyle(fontSize: 11, color: Theme.of(context).hintColor)),
          ])),
          for (final c in cols) DataCell(Text(tr(number(toDouble(m[c.$1]))), style: TextStyle(fontWeight: bold || c.$1 == 'nbv_end' ? FontWeight.w700 : null))),
        ]);
    return ListView(padding: const EdgeInsets.all(16), children: [
      Row(children: [
        IconButton(onPressed: () {
          setState(() => _year--);
          _load();
        }, icon: const Icon(Icons.chevron_left)),
        Text(tr('Exercice $_year'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
        IconButton(onPressed: () {
          setState(() => _year++);
          _load();
        }, icon: const Icon(Icons.chevron_right)),
        const Spacer(),
        OutlinedButton.icon(onPressed: _export, icon: const Icon(Icons.download), label: Text(tr('Excel'))),
      ]),
      const SizedBox(height: 8),
      KpiWrap(children: [
        _Kpi('Valeur brute au 31/12', money(toDouble(t['gross_end']), cur)),
        _Kpi('Dotations de l\'exercice', money(toDouble(t['dep_year']), cur), color: YColors.danger),
        _Kpi('Valeur nette au 31/12', money(toDouble(t['nbv_end']), cur), color: YColors.night),
      ]),
      const SizedBox(height: 12),
      Card(
        clipBehavior: Clip.antiAlias,
        child: SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          child: DataTable(
            columnSpacing: 18,
            headingTextStyle: TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: Theme.of(context).hintColor),
            columns: [DataColumn(label: Text(tr('Catégorie / bien'))), for (final c in cols) DataColumn(label: Text(tr(c.$2)), numeric: true)],
            rows: [
              for (final c in cats) ...[
                row('${c['gl_asset'] ?? ''} ${c['category']}', c, sub: '${c['count']} bien(s)'),
                for (final a in assets.where((x) => (x['category'] ?? 'Sans catégorie') == c['category']))
                  row('   ${a['code'] ?? ''} ${a['name']}', a, sub: a['disposal_date'] != null ? '   sortie le ${dateFr(a['disposal_date'])}' : null),
              ],
              row('TOTAL', t, bold: true),
            ],
          ),
        ),
      ),
      const SizedBox(height: 8),
      Text(tr('Dotations selon les plans d\'amortissement (comptabilisées ou non). Montants en ${cur == 'XOF' ? 'francs CFA' : cur}.'), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
    ]);
  }
}

// ---------------------------------------------------------------------------
// Catégories
// ---------------------------------------------------------------------------

class _CategoriesTab extends StatefulWidget {
  const _CategoriesTab();

  @override
  State<_CategoriesTab> createState() => _CategoriesTabState();
}

class _CategoriesTabState extends State<_CategoriesTab> {
  String _q = '';
  String _status = '';
  Map<String, String?> _f = {};

  Future<void> _edit(BuildContext context, [Map<String, dynamic>? c]) async {
    final name = TextEditingController(text: '${c?['name'] ?? ''}');
    final gl = TextEditingController(text: '${c?['gl_asset'] ?? '2'}');
    final dep = TextEditingController(text: '${c?['gl_depreciation'] ?? '28'}');
    final exp = TextEditingController(text: '${c?['gl_expense'] ?? '681'}');
    final years = TextEditingController(text: '${c?['years'] ?? 5}');
    var method = '${c?['method'] ?? 'linear'}';
    var active = c?['active'] != false;
    final ok = await showFormDialog<bool>(context, title: c == null ? 'Nouvelle catégorie' : 'Catégorie', builder: (d) => StatefulBuilder(
        builder: (d, set) => SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
            Column(mainAxisSize: MainAxisSize.min, children: [
              TextField(controller: name, decoration: InputDecoration(labelText: tr('Nom'))),
              Row(children: [
                Expanded(child: TextField(controller: gl, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Compte (2…)')))),
                const SizedBox(width: 8),
                Expanded(child: TextField(controller: dep, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Amortissements (28…)')))),
                const SizedBox(width: 8),
                Expanded(child: TextField(controller: exp, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Dotations (681)')))),
              ]),
              Row(children: [
                Expanded(
                  child: DropdownButtonFormField<String>(
                    initialValue: method,
                    decoration: InputDecoration(labelText: tr('Méthode par défaut')),
                    items: [for (final e in imMethods.entries) DropdownMenuItem(value: e.key, child: Text(tr(e.value)))],
                    onChanged: (v) => set(() => method = v ?? 'linear'),
                  ),
                ),
                const SizedBox(width: 8),
                SizedBox(width: 120, child: TextField(controller: years, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Durée (ans)')))),
              ]),
              SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Active')), value: active, onChanged: (v) => set(() => active = v)),
            ]),
            const SizedBox(height: 20),
            Align(
              alignment: Alignment.centerRight,
              child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
                TextButton(onPressed: () => Navigator.pop(d, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(d, true), child: Text(tr('Enregistrer'))),
              ]),
            ),
          ]),
        ),
      ));
    if (ok != true || name.text.trim().isEmpty || !context.mounted) return;
    await context.read<SyncEngine>().save('im_categories', {
      'id': c?['id'] ?? newId(),
      'name': name.text.trim(),
      'gl_asset': gl.text.trim(),
      'gl_depreciation': dep.text.trim(),
      'gl_expense': exp.text.trim(),
      'method': method,
      'years': int.tryParse(years.text) ?? 5,
      'active': active,
    });
  }

  @override
  Widget build(BuildContext context) => ValueListenableBuilder(
        valueListenable: LocalDb.listen('im_categories'),
        builder: (context, _, _) {
          final all = imCategories(activeOnly: false);
          bool base(Map<String, dynamic> c) =>
              (_q.isEmpty || '${c['name']} ${c['gl_asset'] ?? ''} ${c['gl_depreciation'] ?? ''} ${c['gl_expense'] ?? ''}'.toLowerCase().contains(_q)) &&
              (_f['method'] == null || '${c['method']}' == _f['method']);
          bool st(Map<String, dynamic> c, String k) => k.isEmpty || (k == 'active') == (c['active'] != false);
          final rows = all.where((c) => base(c) && st(c, _status)).toList();
          return ListView(padding: const EdgeInsets.all(24), children: [
            FilterBar(
              padding: EdgeInsets.zero,
              onSearch: (v) => setState(() => _q = v.toLowerCase()),
              search: _q,
              searchHint: 'Nom, compte…',
              statuses: const {'': 'Toutes', 'active': 'Actives', 'inactive': 'Inactives'},
              status: _status,
              onStatus: (v) => setState(() => _status = v),
              statusCounts: {for (final k in ['', 'active', 'inactive']) k: all.where((c) => base(c) && st(c, k)).length},
              filters: const [FilterSpec('method', 'Méthode', imMethods, icon: Icons.trending_down)],
              values: _f,
              onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
              count: rows.length,
              countLabel: 'catégorie',
              onReset: () => setState(() => (_q = '', _status = '', _f = {})),
              trailing: [FilledButton.icon(onPressed: () => _edit(context), icon: const Icon(Icons.add), label: Text(tr('Nouvelle catégorie')))],
            ),
            const SizedBox(height: 12),
            for (final c in rows)
            Card(
              child: ListTile(
                leading: CircleAvatar(backgroundColor: YColors.bg, child: Text(tr('${c['gl_asset']}'), style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w700))),
                title: Text(tr('${c['name']}')),
                subtitle: Text(tr('${imMethods[c['method']] ?? c['method']}${c['method'] != 'none' ? ' · ${c['years']} an(s)' : ''} · amortissements ${c['gl_depreciation']} · dotations ${c['gl_expense']}')),
                trailing: c['active'] == false ? StatusChip(tr('inactive')) : const Icon(Icons.edit_outlined, size: 18),
                onTap: () => _edit(context, c),
              ),
            ),
          ]);
        },
      );
}

// ---------------------------------------------------------------------------
// Depuis une facture fournisseur
// ---------------------------------------------------------------------------

Future<void> assetsFromInvoice(BuildContext context, Map<String, dynamic> doc) async {
  final lines = [for (final l in (doc['lines'] as List? ?? [])) asMap(l)];
  final selected = <String>{for (final l in lines) '${l['id']}'};
  String? cat = imCategories().firstOrNull?['id'] as String?;
  final cur = context.read<Session>().currency;
  final ok = await showDialog<bool>(
    context: context,
    builder: (d) => StatefulBuilder(
      builder: (d, set) => YAlertDialog(
        title: Text(tr('Créer des immobilisations')),
        content: SizedBox(
          width: 500,
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Text(tr('Une immobilisation en brouillon par ligne cochée (valeur HT de la ligne). À la mise en service, le compte d\'achat est reclassé en compte d\'immobilisation.')),
            const SizedBox(height: 8),
            DropdownButtonFormField<String>(
              initialValue: cat,
              isExpanded: true,
              decoration: InputDecoration(labelText: tr('Catégorie')),
              items: [for (final c in imCategories()) DropdownMenuItem(value: '${c['id']}', child: Text(tr('${c['gl_asset']} · ${c['name']}')))],
              onChanged: (v) => set(() => cat = v),
            ),
            const SizedBox(height: 8),
            for (final l in lines)
              CheckboxListTile(
                dense: true,
                contentPadding: EdgeInsets.zero,
                value: selected.contains('${l['id']}'),
                onChanged: (v) => set(() => v == true ? selected.add('${l['id']}') : selected.remove('${l['id']}')),
                title: Text(tr('${l['description']}')),
                subtitle: Text(tr(money(toDouble(l['subtotal']), cur))),
              ),
          ]),
        ),
        actions: [TextButton(onPressed: () => Navigator.pop(d, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(d, true), child: Text(tr('Créer')))],
      ),
    ),
  );
  if (ok != true || cat == null || selected.isEmpty || !context.mounted) return;
  try {
    final r = await context.read<Session>().api.post('immobilisations/from-document', {'document_id': doc['id'], 'category_id': cat, 'line_ids': selected.toList()});
    if (!context.mounted) return;
    await context.read<SyncEngine>().sync();
    final n = (r['data'] as List).length;
    if (context.mounted) toast(context, n == 0 ? 'Ces lignes ont déjà leurs immobilisations.' : '$n immobilisation(s) créée(s) en brouillon : complétez-les dans Immobilisations puis mettez-les en service.');
  } catch (e) {
    if (context.mounted) toast(context, errorText(e), error: true);
  }
}
