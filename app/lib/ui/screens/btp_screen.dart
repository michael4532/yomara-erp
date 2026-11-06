import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:printing/printing.dart';
import 'package:provider/provider.dart';

import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/filter_bar.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

Color _statusColor(String s) => switch (s) {
      'study' => YColors.blue,
      'won' || 'in_progress' => YColors.warning,
      'received' => const Color(0xFF8E44AD),
      'closed' => YColors.success,
      _ => YColors.muted,
    };

const _units = {'u': 'u', 'ens': 'ens.', 'ff': 'forfait', 'm': 'm', 'm2': 'm²', 'm3': 'm³', 'ml': 'ml', 'kg': 'kg', 't': 't', 'h': 'h', 'j': 'j', 'pt': 'pt'};

String _unit(dynamic u) => _units['$u'] ?? '$u';

String _qty(dynamic v) {
  final d = toDouble(v);
  return d == d.roundToDouble() ? number(d) : number(d, decimals: d * 10 == (d * 10).roundToDouble() ? 1 : 2);
}

Future<void> _openPdf(BuildContext c, String path, String name, {Map<String, String>? query}) async {
  try {
    final bytes = await c.read<Session>().api.download(path, query: query);
    await Printing.layoutPdf(name: name, onLayout: (_) async => Uint8List.fromList(bytes));
  } catch (e) {
    if (c.mounted) toast(c, errorText(e), error: true);
  }
}

Widget _kpi(BuildContext context, String l, String v, {String? sub, Color? color, IconData? icon}) =>
    KpiBox(width: 250, child: StatTile(label: l, value: v, detail: sub, icon: icon, color: color ?? YColors.blue, valueColor: color));

/// BTP & chantiers : portefeuille de chantiers, bordereau chiffré, situations de travaux, journal, coûts.
class BtpScreen extends StatefulWidget {
  const BtpScreen({super.key});

  @override
  State<BtpScreen> createState() => _BtpScreenState();
}

class _BtpScreenState extends State<BtpScreen> {
  late final Session _s = context.read<Session>();
  late final bool _manage = _s.can('btp.manage');
  Map<String, dynamic>? _d;
  Object? _error;
  String _filter = 'active';
  String _q = '';
  final Map<String, String?> _f = {};
  int _gen = 0;

  static bool _statusMatch(Map<String, dynamic> s, String f) => switch (f) {
        'active' => ['won', 'in_progress'].contains(s['status']),
        'study' => s['status'] == 'study',
        'done' => ['received', 'closed'].contains(s['status']),
        'lost' => s['status'] == 'lost',
        _ => true,
      };

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await _s.api.get('btp/dashboard');
      if (mounted) setState(() => (_d = asMap(r), _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _open(int id) async {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => _SitePage(id: id, manage: _manage, canLog: _s.can('btp.log') || _manage)));
    _load();
  }

  @override
  Widget build(BuildContext context) {
    final cur = context.watch<Session>().currency;
    final d = _d == null ? null : asMap(_d!['data']);
    final hint = Theme.of(context).hintColor;
    return Column(children: [
      PageHeader(
        title: tr('BTP & chantiers'),
        subtitle: tr('Études de prix, marchés, situations de travaux, retenues de garantie, journal et coûts de chantier'),
        actions: [
          if (_manage)
            FilledButton.icon(
              onPressed: () async {
                final id = await showFormDialog<int>(context, title: 'Nouveau chantier', builder: (_) => const _SiteDialog());
                if (id != null) _open(id);
              },
              icon: const Icon(Icons.add, size: 18),
              label: Text(tr('Nouveau chantier')),
            ),
        ],
      ),
      Expanded(
        child: _error != null && d == null
            ? OnlineOnlyNotice(error: _error!, onRetry: _load)
            : d == null
                ? const LoadingSkeleton()
                : RefreshIndicator(
                    onRefresh: _load,
                    child: ListView(padding: const EdgeInsets.all(16), children: [
                      KpiWrap(children: [
                        _kpi(context, 'Chantiers en cours', '${d['active']}', sub: 'marchés ${money(toDouble(d['contracts']), cur)} HT', icon: Icons.construction, color: YColors.warning),
                        _kpi(context, 'Reste à réaliser', money(toDouble(d['backlog']), cur), sub: 'carnet de commandes HT', icon: Icons.inventory_outlined, color: YColors.night),
                        _kpi(context, 'Études en cours', money(toDouble(d['pipeline']), cur), sub: '${d['studies']} devis · réussite ${d['won_rate'] == null ? '—' : '${number(toDouble(d['won_rate']), decimals: 0)} %'}', icon: Icons.calculate_outlined, color: YColors.blue),
                        _kpi(context, 'Facturé TTC', money(toDouble(d['invoiced']), cur), sub: 'à encaisser ${money(toDouble(d['receivable']), cur)}', icon: Icons.receipt_long_outlined, color: YColors.success),
                        _kpi(context, 'Retenues de garantie', money(toDouble(d['retention_held']), cur), sub: 'détenues par les clients', icon: Icons.lock_clock_outlined, color: const Color(0xFF8E44AD)),
                        if (toDouble(d['drifting']) > 0) _kpi(context, 'Chantiers en dérive', '${d['drifting']}', sub: 'coûts plus rapides que l\'avancement', icon: Icons.trending_up, color: YColors.danger),
                      ]),
                      const SizedBox(height: 12),
                      ...() {
                        final all = [for (final s in d['sites'] as List) asMap(s)];
                        String client(Map<String, dynamic> s) => '${(s['client'] as Map?)?['name'] ?? ''}';
                        final base = all
                            .where((s) => _q.isEmpty || '${s['name']} ${client(s)} ${s['reference'] ?? ''} ${s['city'] ?? ''}'.toLowerCase().contains(_q))
                            .where((s) => _f['client'] == null || client(s) == _f['client'])
                            .where((s) => _f['city'] == null || '${s['city']}' == _f['city'])
                            .where((s) => _f['drift'] == null || (toDouble(s['drift']) > 10) == (_f['drift'] == 'yes'))
                            .toList();
                        final rows = base.where((s) => _statusMatch(s, _filter)).toList();
                        const statuses = {'': 'Tous', 'active': 'En cours', 'study': 'À l\'étude', 'done': 'Réceptionnés / clôturés', 'lost': 'Perdus'};
                        final clients = <String, String>{for (final s in all) if (client(s).isNotEmpty) client(s): client(s)};
                        final bar = FilterBar(
                          key: ValueKey(_gen),
                          padding: const EdgeInsets.only(bottom: 10),
                          onSearch: (v) => setState(() => _q = v.toLowerCase()),
                          search: _q,
                          searchHint: 'Chantier, client, marché, ville…',
                          statuses: statuses,
                          status: _filter,
                          onStatus: (v) => setState(() => _filter = v),
                          statusCounts: {for (final k in statuses.keys) k: base.where((s) => _statusMatch(s, k)).length},
                          filters: [
                            FilterSpec('client', 'Client', Map.fromEntries(clients.entries.toList()..sort((a, b) => a.value.toLowerCase().compareTo(b.value.toLowerCase()))), icon: Icons.business_outlined),
                            FilterSpec('city', 'Ville', filterOptions(all, 'city'), icon: Icons.place_outlined),
                            const FilterSpec('drift', 'Dérive des coûts', {'yes': 'En dérive', 'no': 'Sous contrôle'}, icon: Icons.trending_up),
                          ],
                          values: _f,
                          onFilter: (k, v) => setState(() => _f[k] = v),
                          count: rows.length,
                          countLabel: 'chantier',
                          onReset: () => setState(() {
                            _filter = 'active';
                            _q = '';
                            _f.clear();
                            _gen++;
                          }),
                        );
                        if (all.isNotEmpty && rows.isEmpty) {
                          return [bar, EmptyState(icon: Icons.filter_alt_off_outlined, title: tr('Aucun résultat'), message: tr('Aucun chantier ne correspond à ces filtres.'))];
                        }
                        if (rows.isEmpty) {
                          return [EmptyState(icon: Icons.construction, title: tr('Aucun chantier'), message: tr('Créez un chantier, chiffrez son bordereau (lots et ouvrages), puis suivez-le jusqu\'à la libération de la retenue de garantie.'))];
                        }
                        return [
                          bar,
                          for (final s in rows)
                            Card(
                              child: InkWell(
                                borderRadius: BorderRadius.circular(16),
                                onTap: () => _open(s['id'] as int),
                                child: Padding(
                                  padding: const EdgeInsets.all(14),
                                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                                    Row(children: [
                                      CircleAvatar(backgroundColor: _statusColor('${s['status']}').withValues(alpha: .12), child: Icon(Icons.construction, color: _statusColor('${s['status']}'))),
                                      const SizedBox(width: 12),
                                      Expanded(
                                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                                          Text(tr('${s['name']}'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15.5)),
                                          Text(tr([(s['client'] as Map?)?['name'] ?? 'Client à renseigner', if (s['reference'] != null) 'marché ${s['reference']}', if (s['city'] != null) '${s['city']}'].join(' · ')),
                                              style: TextStyle(color: hint, fontSize: 12.5)),
                                        ]),
                                      ),
                                      Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
                                        Text(tr(money(toDouble(s['status'] == 'study' ? (s['estimate'] as Map)['price'] : s['contract_amount']), cur)), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15)),
                                        StatusChip('${s['status_label']}', color: _statusColor('${s['status']}')),
                                      ]),
                                    ]),
                                    if (s['status'] != 'study' && s['status'] != 'lost') ...[
                                      const SizedBox(height: 10),
                                      ClipRRect(borderRadius: BorderRadius.circular(6), child: LinearProgressIndicator(value: (toDouble(s['progress']) / 100).clamp(0, 1), minHeight: 8)),
                                      const SizedBox(height: 6),
                                      Text(tr([
                                        'avancement ${number(toDouble(s['progress']), decimals: 1)} %',
                                        if (s['cost_consumed'] != null) 'budget consommé ${number(toDouble(s['cost_consumed']), decimals: 1)} %',
                                        'facturé ${money(toDouble(s['invoiced']), cur)}',
                                        'marge prévue ${money(toDouble(s['margin_forecast']), cur)}',
                                      ].join(' · ')), style: TextStyle(color: toDouble(s['drift']) > 10 ? YColors.danger : hint, fontSize: 12.5)),
                                    ],
                                  ]),
                                ),
                              ),
                            ),
                        ];
                      }(),
                    ]),
                  ),
      ),
    ]);
  }
}

// ---------------------------------------------------------------------------
// Création / modification d'un chantier
// ---------------------------------------------------------------------------

class _SiteDialog extends StatefulWidget {
  const _SiteDialog({this.site});

  final Map<String, dynamic>? site;

  @override
  State<_SiteDialog> createState() => _SiteDialogState();
}

class _SiteDialogState extends State<_SiteDialog> {
  late final Map<String, dynamic> s = widget.site ?? {};
  late final _name = TextEditingController(text: '${s['name'] ?? ''}');
  late final _client = TextEditingController();
  late final _phone = TextEditingController();
  late final _address = TextEditingController(text: '${s['address'] ?? ''}');
  late final _city = TextEditingController(text: '${s['city'] ?? 'Abidjan'}');
  late final _ref = TextEditingController(text: '${s['reference'] ?? ''}');
  late final _tax = TextEditingController(text: number(toDouble(s['tax_rate'] ?? 18), decimals: 0));
  late final _ret = TextEditingController(text: number(toDouble(s['retention_rate'] ?? 5), decimals: 0));
  late final _adv = TextEditingController(text: number(toDouble(s['advance_rate'] ?? 0), decimals: 0));
  bool _busy = false;

  Future<void> _save() async {
    setState(() => _busy = true);
    try {
      final body = {
        'name': _name.text.trim(), 'address': _address.text.trim(), 'city': _city.text.trim(), 'reference': _ref.text.trim().isEmpty ? null : _ref.text.trim(),
        if (_client.text.trim().isNotEmpty) ...{'client_name': _client.text.trim(), 'client_phone': _phone.text.trim()},
        'tax_rate': toDouble(_tax.text), 'retention_rate': toDouble(_ret.text), 'advance_rate': toDouble(_adv.text),
      };
      final api = context.read<Session>().api;
      final r = s['id'] == null ? await api.post('btp/sites', body) : await api.put('btp/sites/${s['id']}', body);
      if (mounted) Navigator.pop(context, (r['data'] as Map)['id'] as int);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
        Column(mainAxisSize: MainAxisSize.min, children: [
          TextField(controller: _name, decoration: InputDecoration(labelText: tr('Nom du chantier *'), hintText: tr('Villa duplex Bingerville, réfection bureaux Plateau…'))),
          const SizedBox(height: 8),
          Row(children: [
            Expanded(child: TextField(controller: _client, decoration: InputDecoration(labelText: trn(s['client'] == null ? 'Client (maître d\'ouvrage)' : 'Changer de client'), helperText: trn(s['client'] != null ? 'Actuel : ${(s['client'] as Map)['name']}' : null)))),
            const SizedBox(width: 10),
            Expanded(child: TextField(controller: _phone, keyboardType: TextInputType.phone, decoration: InputDecoration(labelText: tr('Téléphone')))),
          ]),
          const SizedBox(height: 8),
          Row(children: [
            Expanded(flex: 2, child: TextField(controller: _address, decoration: InputDecoration(labelText: tr('Adresse du chantier')))),
            const SizedBox(width: 10),
            Expanded(child: TextField(controller: _city, decoration: InputDecoration(labelText: tr('Ville')))),
          ]),
          const SizedBox(height: 8),
          TextField(controller: _ref, decoration: InputDecoration(labelText: tr('N° de marché ou bon de commande (facultatif)'))),
          const SizedBox(height: 8),
          Row(children: [
            Expanded(child: TextField(controller: _tax, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('TVA'), suffixText: '%'))),
            const SizedBox(width: 10),
            Expanded(child: TextField(controller: _adv, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Avance de démarrage'), suffixText: '%'))),
            const SizedBox(width: 10),
            Expanded(child: TextField(controller: _ret, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Retenue de garantie'), suffixText: '%'))),
          ]),
        ]),
        const SizedBox(height: 20),
        Align(
          alignment: Alignment.centerRight,
          child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
            TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Annuler'))),
            FilledButton(onPressed: _busy ? null : _save, child: Text(tr('Enregistrer'))),
          ]),
        ),
      ]),
    );
  }
}

// ---------------------------------------------------------------------------
// Fiche chantier
// ---------------------------------------------------------------------------

class _SitePage extends StatefulWidget {
  const _SitePage({required this.id, required this.manage, required this.canLog});

  final int id;
  final bool manage;
  final bool canLog;

  @override
  State<_SitePage> createState() => _SitePageState();
}

class _SitePageState extends State<_SitePage> {
  Map<String, dynamic>? _s;
  Map<String, dynamic> _meta = {};
  Object? _error;

  Session get _session => context.read<Session>();

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await _session.api.get('btp/sites/${widget.id}');
      _apply(r);
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  void _apply(dynamic r) {
    if (!mounted) return;
    setState(() => (_s = asMap(r['data']), _meta = asMap(r['meta']), _error = null));
  }

  Future<void> _post(String path, String done, [Map<String, dynamic>? body]) async {
    try {
      _apply(await _session.api.post('btp/sites/${widget.id}/$path', body ?? {}));
      if (mounted) toast(context, done);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = _s;
    return DefaultTabController(
      length: 5,
      child: Scaffold(
        appBar: AppBar(
          title: Text(tr(s == null ? 'Chantier' : '${s['name']}')),
          actions: [
            if (s != null && widget.manage)
              IconButton(
                tooltip: tr('Modifier'),
                onPressed: () async {
                  final id = await showFormDialog<int>(context, title: s['id'] == null ? 'Nouveau chantier' : 'Modifier le chantier', builder: (_) => _SiteDialog(site: s));
                  if (id != null) _load();
                },
                icon: const Icon(Icons.edit_outlined),
              ),
          ],
          bottom: TabBar(isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [
            Tab(text: tr('Synthèse')),
            Tab(text: tr('Bordereau')),
            Tab(text: tr('Situations')),
            Tab(text: tr('Journal')),
            Tab(text: tr('Coûts')),
          ]),
        ),
        body: _error != null && s == null
            ? OnlineOnlyNotice(error: _error!, onRetry: _load)
            : s == null
                ? const LoadingSkeleton()
                : TabBarView(children: [
                    _summary(s),
                    _Items(site: s, meta: _meta, manage: widget.manage, onSaved: _apply),
                    _Situations(site: s, manage: widget.manage, onChanged: _apply),
                    _Journal(site: s, meta: _meta, canLog: widget.canLog, onChanged: _apply),
                    _costs(s),
                  ]),
      ),
    );
  }

  Widget _summary(Map<String, dynamic> s) {
    final cur = context.watch<Session>().currency;
    final hint = Theme.of(context).hintColor;
    final est = asMap(s['estimate']);
    final st = '${s['status']}';
    final docs = [for (final d in s['documents'] as List) asMap(d)];
    return ListView(padding: const EdgeInsets.all(16), children: [
      Row(children: [
        StatusChip('${s['status_label']}', color: _statusColor(st)),
        const SizedBox(width: 10),
        Expanded(
          child: Text(tr([(s['client'] as Map?)?['name'] ?? 'Client à renseigner', if (s['address'] != null) '${s['address']}', if (s['reference'] != null) 'marché ${s['reference']}'].join(' · ')),
              style: TextStyle(color: hint)),
        ),
      ]),
      const SizedBox(height: 12),
      KpiWrap(children: [
        _kpi(context, st == 'study' ? 'Montant du devis HT' : 'Marché HT', money(toDouble(st == 'study' ? est['price'] : s['contract_amount']), cur), sub: 'TTC ${money(toDouble(est['ttc']), cur)}', icon: Icons.request_quote_outlined),
        _kpi(context, 'Déboursé sec prévu', money(toDouble(est['cost']), cur),
            sub: 'marge ${money(toDouble(est['margin']), cur)}${est['margin_rate'] != null ? ' (${number(toDouble(est['margin_rate']), decimals: 1)} %)' : ''}', icon: Icons.calculate_outlined, color: YColors.night),
        if (st != 'study') ...[
          _kpi(context, 'Avancement facturé', '${number(toDouble(s['progress']), decimals: 1)} %', sub: '${money(toDouble(s['works_cumul']), cur)} · reste ${money(toDouble(s['remaining']), cur)}', icon: Icons.stacked_bar_chart, color: YColors.warning),
          _kpi(context, 'Facturé TTC', money(toDouble(s['invoiced']), cur), sub: 'encaissé ${money(toDouble(s['paid']), cur)}', icon: Icons.receipt_long_outlined, color: YColors.success),
          _kpi(context, 'Retenue de garantie', money(toDouble(s['retention_held']), cur), sub: s['release_invoice_id'] != null ? 'libérée' : '${s['retention_rate']} % du TTC', icon: Icons.lock_clock_outlined, color: const Color(0xFF8E44AD)),
          if (toDouble(s['advance_amount']) > 0)
            _kpi(context, 'Avance de démarrage', money(toDouble(s['advance_amount']), cur), sub: 'remboursée ${money(toDouble(s['advance_repaid']), cur)}', icon: Icons.savings_outlined, color: YColors.blue),
          _kpi(context, 'Coûts réels', money(toDouble(s['actual_cost']), cur),
              sub: s['cost_consumed'] == null ? 'achats, main-d\'œuvre, frais' : '${number(toDouble(s['cost_consumed']), decimals: 1)} % du déboursé', icon: Icons.payments_outlined, color: toDouble(s['drift']) > 10 ? YColors.danger : YColors.success),
          _kpi(context, 'Marge prévisionnelle', money(toDouble(s['margin_forecast']), cur), sub: 'à date ${money(toDouble(s['margin_to_date']), cur)}', icon: Icons.trending_up, color: toDouble(s['margin_forecast']) >= 0 ? YColors.success : YColors.danger),
        ],
      ]),
      if (toDouble(s['drift']) > 10)
        Card(
          color: YColors.danger.withValues(alpha: .08),
          child: ListTile(
            leading: const Icon(Icons.warning_amber_rounded, color: YColors.danger),
            title: Text(tr('Dérive : ${number(toDouble(s['cost_consumed']), decimals: 1)} % du budget consommé pour ${number(toDouble(s['progress']), decimals: 1)} % d\'avancement')),
            subtitle: Text(tr('Vérifiez les achats et les heures du chantier, ou facturez une situation si l\'avancement réel est plus élevé.')),
          ),
        ),
      const SizedBox(height: 12),
      if (widget.manage)
        Wrap(spacing: 8, runSpacing: 8, children: [
          if (st == 'study') ...[
            OutlinedButton.icon(onPressed: () => _post('quote', 'Devis établi dans les Ventes.'), icon: const Icon(Icons.request_quote_outlined, size: 18), label: Text(tr(s['quote_id'] == null ? 'Établir le devis' : 'Refaire le devis'))),
            FilledButton.icon(
              onPressed: () async {
                if (await confirm(context, tr('Marché signé ?'), tr('Le montant du marché est figé sur le bordereau (${money(toDouble(est['price']), _session.currency)} HT) et le chantier démarre.'), ok: tr('Marché signé'))) {
                  _post('accept', 'Marché signé : le chantier est en cours.');
                }
              },
              icon: const Icon(Icons.handshake_outlined, size: 18),
              label: Text(tr('Marché signé')),
            ),
            TextButton(onPressed: () => _post('lost', 'Chantier marqué perdu.'), child: Text(tr('Perdu'))),
          ],
          if (st == 'in_progress' || st == 'won') ...[
            if (toDouble(s['advance_rate']) > 0 && s['advance_invoice_id'] == null)
              OutlinedButton.icon(onPressed: () => _post('advance', 'Avance de démarrage facturée.'), icon: const Icon(Icons.savings_outlined, size: 18), label: Text(tr('Facturer l\'avance (${number(toDouble(s['advance_rate']))} %)'))),
            OutlinedButton.icon(
              onPressed: () async {
                if (await confirm(context, tr('Réception des travaux ?'), tr('Le chantier passe « Réceptionné » : le délai de garantie commence, la retenue sera libérée à son terme.'), ok: tr('Réceptionner'))) {
                  _post('receive', 'Travaux réceptionnés.');
                }
              },
              icon: const Icon(Icons.verified_outlined, size: 18),
              label: Text(tr('Réception des travaux')),
            ),
          ],
          if (st == 'received' && toDouble(s['retention_held']) > 0)
            FilledButton.icon(onPressed: () => _post('release', 'Retenue de garantie facturée au client.'), icon: const Icon(Icons.lock_open, size: 18), label: Text(tr('Libérer la retenue (${money(toDouble(s['retention_held']), cur)})'))),
        ]),
      const SizedBox(height: 12),
      Wrap(spacing: 8, runSpacing: 8, children: [
        OutlinedButton.icon(onPressed: () => _openPdf(context, 'btp/sites/${s['id']}/estimate.pdf', 'Devis ${s['name']}'), icon: const Icon(Icons.picture_as_pdf_outlined, size: 18), label: Text(tr('Devis quantitatif PDF'))),
        if (widget.manage)
          OutlinedButton.icon(onPressed: () => _openPdf(context, 'btp/sites/${s['id']}/estimate.pdf', 'Étude de prix ${s['name']}', query: {'internal': '1'}), icon: const Icon(Icons.lock_outline, size: 18), label: Text(tr('Étude de prix (interne)'))),
      ]),
      if (docs.isNotEmpty) ...[
        const SizedBox(height: 12),
        SectionCard(
          title: tr('Documents'),
          child: Column(children: [
            for (final d in docs)
              if (d['doc'] != null)
                ListTile(dense: true, contentPadding: EdgeInsets.zero, leading: const Icon(Icons.description_outlined), title: Text(tr('${d['label']} · ${(d['doc'] as Map)['number'] ?? ''}')), trailing: Text(tr(money(toDouble((d['doc'] as Map)['total']), cur)), style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w700))),
          ]),
        ),
      ],
    ]);
  }

  Widget _costs(Map<String, dynamic> s) {
    final cur = context.watch<Session>().currency;
    final hint = Theme.of(context).hintColor;
    final c = s['costs'] == null ? null : asMap(s['costs']);
    final est = asMap(s['estimate']);
    if (c == null) return EmptyState(icon: Icons.payments_outlined, title: tr('Coûts indisponibles'), message: tr('Le module Projets suit les coûts du chantier.'));
    Widget row(String l, double v, {bool bold = false, Color? color}) => Padding(
          padding: const EdgeInsets.symmetric(vertical: 5),
          child: Row(children: [
            Expanded(child: Text(tr(l), style: TextStyle(fontWeight: bold ? FontWeight.w700 : null))),
            Text(tr(money(v, cur)), style: TextStyle(fontWeight: bold ? FontWeight.w800 : FontWeight.w600, color: color)),
          ]),
        );
    return ListView(padding: const EdgeInsets.all(16), children: [
      SectionCard(
        title: tr('Coûts du chantier'),
        child: Column(children: [
          row('Déboursé sec prévu (bordereau)', toDouble(est['cost']), bold: true),
          const Divider(),
          row('Achats facturés (factures fournisseurs du chantier)', toDouble(c['purchases'])),
          row('Main-d\'œuvre (${number(toDouble(c['hours']), decimals: 1)} h pointées)', toDouble(c['labour'])),
          row('Notes de frais', toDouble(c['expenses'])),
          row('Coûts réels', toDouble(c['actual_cost']), bold: true),
          row('Commandes fournisseurs en attente de facture', toDouble(c['commitments'])),
          row('Coûts engagés', toDouble(c['committed_cost']), bold: true, color: toDouble(c['committed_cost']) > toDouble(est['cost']) ? YColors.danger : null),
          const Divider(),
          row('Reste du déboursé', toDouble(est['cost']) - toDouble(c['committed_cost']), color: toDouble(est['cost']) - toDouble(c['committed_cost']) < 0 ? YColors.danger : YColors.success),
        ]),
      ),
      const SizedBox(height: 8),
      Text(tr('Les achats, bons de commande, heures et notes de frais rattachés au projet « ${'Chantier — ${s['name']}'} » sont comptés ici. Choisissez ce projet sur vos factures fournisseurs et dans les feuilles de temps.'),
          style: TextStyle(color: hint, fontSize: 12.5)),
    ]);
  }
}

// ---------------------------------------------------------------------------
// Bordereau (lots et ouvrages)
// ---------------------------------------------------------------------------

class _Items extends StatefulWidget {
  const _Items({required this.site, required this.meta, required this.manage, required this.onSaved});

  final Map<String, dynamic> site;
  final Map<String, dynamic> meta;
  final bool manage;
  final void Function(dynamic) onSaved;

  @override
  State<_Items> createState() => _ItemsState();
}

class _ItemsState extends State<_Items> {
  late List<Map<String, dynamic>> _items = [for (final i in widget.site['items'] as List) asMap(i)];
  bool _dirty = false;
  bool _busy = false;

  bool get _locked => (widget.site['situations_count'] as num? ?? 0) > 0;

  @override
  void didUpdateWidget(covariant _Items old) {
    super.didUpdateWidget(old);
    if (!_dirty) _items = [for (final i in widget.site['items'] as List) asMap(i)];
  }

  Future<void> _edit([int? index]) async {
    final it = index == null ? <String, dynamic>{'lot': _items.isEmpty ? 'Gros œuvre' : _items.last['lot'], 'unit': 'm2'} : Map<String, dynamic>.from(_items[index]);
    final lots = _items.map((i) => '${i['lot']}').toSet().toList();
    final lot = TextEditingController(text: '${it['lot'] ?? ''}');
    final code = TextEditingController(text: '${it['code'] ?? ''}');
    final des = TextEditingController(text: '${it['designation'] ?? ''}');
    final qty = TextEditingController(text: it['quantity'] == null ? '' : _qty(it['quantity']).replaceAll(' ', '').replaceAll(' ', ''));
    final cost = TextEditingController(text: it['unit_cost'] == null ? '' : '${toDouble(it['unit_cost']).round()}');
    final price = TextEditingController(text: it['unit_price'] == null ? '' : '${toDouble(it['unit_price']).round()}');
    var unit = '${it['unit'] ?? 'u'}';
    final units = asMap(widget.meta['units']);
    final ok = await showFormDialog<bool>(context, title: index == null ? 'Nouvel ouvrage' : 'Modifier l\'ouvrage', builder: (c) => StatefulBuilder(
        builder: (c, set) => SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
            Column(mainAxisSize: MainAxisSize.min, children: [
              Row(children: [
                Expanded(
                  flex: 3,
                  child: Autocomplete<String>(
                    initialValue: TextEditingValue(text: lot.text),
                    optionsBuilder: (v) => {...lots, 'Gros œuvre', 'Terrassement', 'Charpente & couverture', 'Menuiserie', 'Plomberie', 'Électricité', 'Carrelage', 'Peinture', 'VRD'}
                        .where((x) => x.toLowerCase().contains(v.text.toLowerCase())),
                    onSelected: (v) => lot.text = v,
                    fieldViewBuilder: (c, ctl, f, sub) => TextField(controller: ctl, focusNode: f, onChanged: (v) => lot.text = v, decoration: InputDecoration(labelText: tr('Lot *'))),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(child: TextField(controller: code, decoration: const InputDecoration(labelText: 'N°', hintText: '1.1'))),
              ]),
              const SizedBox(height: 8),
              TextField(controller: des, decoration: InputDecoration(labelText: tr('Désignation de l\'ouvrage *'))),
              const SizedBox(height: 8),
              Row(children: [
                Expanded(
                  child: DropdownButtonFormField<String>(
                    initialValue: units.containsKey(unit) ? unit : 'u',
                    decoration: InputDecoration(labelText: tr('Unité')),
                    items: [for (final e in units.entries) DropdownMenuItem(value: e.key, child: Text(tr('${e.value}')))],
                    onChanged: (v) => unit = v ?? unit,
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(child: TextField(controller: qty, keyboardType: const TextInputType.numberWithOptions(decimal: true), decoration: InputDecoration(labelText: tr('Quantité *')))),
              ]),
              const SizedBox(height: 8),
              Row(children: [
                Expanded(child: TextField(controller: cost, keyboardType: TextInputType.number, onChanged: (_) => set(() {}), decoration: InputDecoration(labelText: tr('Déboursé unitaire'), helperText: tr('matériaux + main-d\'œuvre + matériel')))),
                const SizedBox(width: 10),
                Expanded(child: TextField(controller: price, keyboardType: TextInputType.number, onChanged: (_) => set(() {}), decoration: InputDecoration(labelText: tr('Prix unitaire HT *')))),
              ]),
              const SizedBox(height: 8),
              Row(children: [
                TextButton(onPressed: () => set(() => price.text = '${(toDouble(cost.text) * 1.35).round()}'), child: Text(tr('Prix = déboursé × 1,35'))),
                TextButton(onPressed: () => set(() => price.text = '${(toDouble(cost.text) * 1.5).round()}'), child: const Text('× 1,50')),
                const Spacer(),
                if (toDouble(price.text) > 0 && toDouble(cost.text) > 0)
                  Text(tr('marge ${number((toDouble(price.text) - toDouble(cost.text)) * 100 / toDouble(price.text), decimals: 1)} %'), style: TextStyle(color: Theme.of(c).hintColor)),
              ]),
            ]),
            const SizedBox(height: 20),
            Align(
              alignment: Alignment.centerRight,
              child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
                if (index != null) TextButton(style: TextButton.styleFrom(foregroundColor: YColors.danger), onPressed: () => Navigator.pop(c, null), child: Text(tr('Supprimer'))),
                TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))),
                FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('OK'))),
              ]),
            ),
          ]),
        ),
      ));
    if (ok == null && index != null) {
      setState(() => (_items.removeAt(index), _dirty = true));
      return;
    }
    if (ok != true || des.text.trim().isEmpty || lot.text.trim().isEmpty) return;
    final row = {
      if (it['id'] != null) 'id': it['id'], 'lot': lot.text.trim(), 'code': code.text.trim().isEmpty ? null : code.text.trim(), 'designation': des.text.trim(), 'unit': unit,
      'quantity': toDouble(qty.text), 'unit_cost': toDouble(cost.text), 'unit_price': toDouble(price.text),
    };
    setState(() {
      if (index == null) {
        final last = _items.lastIndexWhere((i) => i['lot'] == row['lot']);
        last < 0 ? _items.add(row) : _items.insert(last + 1, row);
      } else {
        _items[index] = row;
      }
      _dirty = true;
    });
  }

  Future<void> _save() async {
    setState(() => _busy = true);
    try {
      final r = await context.read<Session>().api.put('btp/sites/${widget.site['id']}/items', {
        'items': [for (final i in _items) {for (final k in ['id', 'lot', 'code', 'designation', 'unit', 'quantity', 'unit_cost', 'unit_price']) k: i[k]}],
      });
      setState(() => _dirty = false);
      widget.onSaved(r);
      if (mounted) toast(context, tr('Bordereau enregistré.'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final cur = context.watch<Session>().currency;
    final hint = Theme.of(context).hintColor;
    final units = asMap(widget.meta['units']);
    final edit = widget.manage && !_locked;
    final price = _items.fold<double>(0, (a, i) => a + toDouble(i['quantity']) * toDouble(i['unit_price']));
    final cost = _items.fold<double>(0, (a, i) => a + toDouble(i['quantity']) * toDouble(i['unit_cost']));
    final lots = <String, List<int>>{};
    for (var k = 0; k < _items.length; k++) {
      lots.putIfAbsent('${_items[k]['lot']}', () => []).add(k);
    }
    return Column(children: [
      Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
        child: Wrap(spacing: 10, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
          Text(tr('Total HT ${money(price, cur)}'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
          Text(tr('déboursé ${money(cost, cur)} · marge ${money(price - cost, cur)}${price > 0 ? ' (${number((price - cost) * 100 / price, decimals: 1)} %)' : ''}'), style: TextStyle(color: hint)),
          if (edit) ...[
            OutlinedButton.icon(onPressed: () => _edit(), icon: const Icon(Icons.add, size: 18), label: Text(tr('Ouvrage'))),
            FilledButton.icon(onPressed: _dirty && !_busy ? _save : null, icon: const Icon(Icons.save_outlined, size: 18), label: Text(tr('Enregistrer'))),
          ],
          if (_locked) Text(tr('Bordereau figé : des situations ont été établies.'), style: TextStyle(color: hint, fontStyle: FontStyle.italic)),
        ]),
      ),
      Expanded(
        child: _items.isEmpty
            ? EmptyState(icon: Icons.list_alt, title: tr('Bordereau vide'), message: tr('Ajoutez les ouvrages par lot : quantité, unité, déboursé sec et prix de vente.'))
            : ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 16), children: [
                for (final e in lots.entries)
                  SectionCard(
                    title: '${e.key} · ${money(e.value.fold<double>(0, (a, k) => a + toDouble(_items[k]['quantity']) * toDouble(_items[k]['unit_price'])), cur)}',
                    child: Column(children: [
                      for (final k in e.value)
                        InkWell(
                          onTap: edit ? () => _edit(k) : null,
                          child: Padding(
                            padding: const EdgeInsets.symmetric(vertical: 6),
                            child: Row(children: [
                              SizedBox(width: 44, child: Text(tr('${_items[k]['code'] ?? ''}'), style: TextStyle(color: hint))),
                              Expanded(child: Text(tr('${_items[k]['designation']}'))),
                              SizedBox(width: 110, child: Text(tr('${_qty(_items[k]['quantity'])} ${units[_items[k]['unit']] ?? _items[k]['unit']}'), textAlign: TextAlign.right)),
                              SizedBox(width: 110, child: Text(tr(money(toDouble(_items[k]['unit_price']), cur)), textAlign: TextAlign.right, style: TextStyle(color: hint))),
                              SizedBox(width: 130, child: Text(tr(money(toDouble(_items[k]['quantity']) * toDouble(_items[k]['unit_price']), cur)), textAlign: TextAlign.right, style: const TextStyle(fontWeight: FontWeight.w700))),
                            ]),
                          ),
                        ),
                    ]),
                  ),
              ]),
      ),
    ]);
  }
}

// ---------------------------------------------------------------------------
// Situations de travaux
// ---------------------------------------------------------------------------

class _Situations extends StatefulWidget {
  const _Situations({required this.site, required this.manage, required this.onChanged});

  final Map<String, dynamic> site;
  final bool manage;
  final void Function(dynamic) onChanged;

  @override
  State<_Situations> createState() => _SituationsState();
}

class _SituationsState extends State<_Situations> {
  Future<void> _new() async {
    final r = await showDialog<dynamic>(context: context, builder: (_) => _SituationDialog(site: widget.site));
    if (r != null) {
      widget.onChanged(r);
      if (mounted) toast(context, tr('Situation établie et facturée.'));
    }
  }

  @override
  Widget build(BuildContext context) {
    final cur = context.watch<Session>().currency;
    final hint = Theme.of(context).hintColor;
    final s = widget.site;
    final rows = [for (final x in s['situations'] as List) asMap(x)];
    final open = ['in_progress', 'won'].contains(s['status']);
    return ListView(padding: const EdgeInsets.all(16), children: [
      if (widget.manage && open)
        Align(alignment: Alignment.centerLeft, child: FilledButton.icon(onPressed: _new, icon: const Icon(Icons.add_chart, size: 18), label: Text(tr('Nouvelle situation de travaux')))),
      if (!open && rows.isEmpty) Text(tr(s['status'] == 'study' ? 'Les situations se font une fois le marché signé.' : 'Aucune situation.'), style: TextStyle(color: hint)),
      const SizedBox(height: 10),
      for (final x in rows.reversed)
        Card(
          child: ListTile(
            leading: CircleAvatar(child: Text(tr('${x['number']}'))),
            title: Text(tr('Situation n° ${x['number']} au ${dateFr(x['date'])}'), style: const TextStyle(fontWeight: FontWeight.w700)),
            subtitle: Text(
              tr('Travaux ${money(toDouble(x['works_amount']), cur)} HT · cumul ${money(toDouble(x['works_cumul']), cur)}'
              '${toDouble(x['advance_repaid']) > 0 ? ' · avance −${money(toDouble(x['advance_repaid']), cur)}' : ''}'
              '${toDouble(x['retention']) > 0 ? ' · retenue −${money(toDouble(x['retention']), cur)}' : ''}'
              '${x['invoice'] != null ? ' · facture ${(x['invoice'] as Map)['number']}' : ''}'),
              style: TextStyle(color: hint),
            ),
            trailing: Wrap(crossAxisAlignment: WrapCrossAlignment.center, children: [
              Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.end, children: [
                Text(tr(money(toDouble(x['net']), cur)), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15)),
                if (x['invoice'] != null)
                  StatusChip(toDouble((x['invoice'] as Map)['amount_paid']) >= toDouble((x['invoice'] as Map)['total']) - 0.5 ? 'Payée' : 'À encaisser',
                      color: toDouble((x['invoice'] as Map)['amount_paid']) >= toDouble((x['invoice'] as Map)['total']) - 0.5 ? YColors.success : YColors.warning),
              ]),
              IconButton(tooltip: tr('Décompte PDF'), onPressed: () => _openPdf(context, 'btp/situations/${x['id']}/pdf', 'Situation n° ${x['number']}'), icon: const Icon(Icons.picture_as_pdf_outlined)),
            ]),
          ),
        ),
    ]);
  }
}

class _SituationDialog extends StatefulWidget {
  const _SituationDialog({required this.site});

  final Map<String, dynamic> site;

  @override
  State<_SituationDialog> createState() => _SituationDialogState();
}

class _SituationDialogState extends State<_SituationDialog> {
  late final List<Map<String, dynamic>> _rows = [for (final r in ((widget.site['next'] as Map)['rows'] as List)) asMap(r)];
  late final Map<int, TextEditingController> _ctl = {for (final r in _rows) r['item_id'] as int: TextEditingController(text: _qty(r['cumul']).replaceAll(' ', '').replaceAll(' ', ''))};
  Map<String, dynamic>? _calc;
  bool _busy = false;

  Map<String, double> get _progress => {for (final e in _ctl.entries) '${e.key}': toDouble(e.value.text)};

  Future<void> _preview() async {
    try {
      final r = await context.read<Session>().api.post('btp/sites/${widget.site['id']}/situations/preview', {'progress': _progress});
      if (mounted) setState(() => _calc = asMap(r['data']));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _save() async {
    setState(() => _busy = true);
    try {
      final r = await context.read<Session>().api.post('btp/sites/${widget.site['id']}/situations', {'progress': _progress});
      if (mounted) Navigator.pop(context, r);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  void initState() {
    super.initState();
    _calc = asMap(widget.site['next']);
  }

  @override
  Widget build(BuildContext context) {
    final cur = context.watch<Session>().currency;
    final hint = Theme.of(context).hintColor;
    final c = _calc;
    return YAlertDialog(
      title: Text(tr('Situation n° ${c?['number'] ?? ''} — quantités cumulées exécutées')),
      content: SizedBox(
        width: 760,
        height: 560,
        child: Column(children: [
          Expanded(
            child: ListView(children: [
              for (final r in _rows)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 4),
                  child: Row(children: [
                    Expanded(
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Text(tr('${r['code'] ?? ''} ${r['designation']}'.trim()), style: const TextStyle(fontWeight: FontWeight.w600)),
                        Text(tr('${r['lot']} · marché ${_qty(r['quantity'])} ${_unit(r['unit'])} · déjà ${_qty(r['previous'])}'), style: TextStyle(color: hint, fontSize: 12)),
                      ]),
                    ),
                    for (final p in [50, 100])
                      TextButton(
                        onPressed: () => setState(() => _ctl[r['item_id'] as int]!.text = _qty(toDouble(r['quantity']) * p / 100).replaceAll(' ', '').replaceAll(' ', '')),
                        child: Text(tr('$p %')),
                      ),
                    SizedBox(
                      width: 110,
                      child: TextField(
                        controller: _ctl[r['item_id'] as int],
                        keyboardType: const TextInputType.numberWithOptions(decimal: true),
                        textAlign: TextAlign.right,
                        decoration: InputDecoration(isDense: true, suffixText: trn(_unit(r['unit']))),
                        onSubmitted: (_) => _preview(),
                      ),
                    ),
                  ]),
                ),
            ]),
          ),
          const Divider(),
          if (c != null)
            Wrap(spacing: 18, runSpacing: 6, children: [
              Text(tr('Cumul ${money(toDouble(c['works_cumul']), cur)} (${number(toDouble(c['progress_pct']), decimals: 1)} %)')),
              Text(tr('Période ${money(toDouble(c['works_amount']), cur)} HT'), style: const TextStyle(fontWeight: FontWeight.w700)),
              if (toDouble(c['advance_repaid']) > 0) Text(tr('Avance −${money(toDouble(c['advance_repaid']), cur)}')),
              Text(tr('TVA ${money(toDouble(c['tax']), cur)}')),
              if (toDouble(c['retention']) > 0) Text(tr('Retenue −${money(toDouble(c['retention']), cur)}')),
              Text(tr('Net ${money(toDouble(c['net']), cur)}'), style: const TextStyle(fontWeight: FontWeight.w800, color: YColors.success)),
            ]),
        ]),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Annuler'))),
        OutlinedButton(onPressed: _preview, child: Text(tr('Calculer'))),
        FilledButton(onPressed: _busy ? null : _save, child: Text(tr('Établir et facturer'))),
      ],
    );
  }
}

// ---------------------------------------------------------------------------
// Journal de chantier
// ---------------------------------------------------------------------------

class _Journal extends StatelessWidget {
  const _Journal({required this.site, required this.meta, required this.canLog, required this.onChanged});

  final Map<String, dynamic> site;
  final Map<String, dynamic> meta;
  final bool canLog;
  final void Function(dynamic) onChanged;

  Future<void> _add(BuildContext context) async {
    final weather = asMap(meta['weather']);
    var w = 'soleil';
    var date = DateTime.now();
    final workers = TextEditingController();
    final done = TextEditingController();
    final inc = TextEditingController();
    final ok = await showFormDialog<bool>(context, title: 'Journal de chantier', builder: (c) => StatefulBuilder(
        builder: (c, set) => SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
            Column(mainAxisSize: MainAxisSize.min, children: [
              Row(children: [
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: () async {
                      final d = await showDatePicker(context: c, initialDate: date, firstDate: DateTime(2020), lastDate: DateTime.now());
                      if (d != null) set(() => date = d);
                    },
                    icon: const Icon(Icons.event, size: 18),
                    label: Text(tr(dateFr(isoDate(date)))),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: DropdownButtonFormField<String>(
                    initialValue: w,
                    decoration: InputDecoration(labelText: tr('Météo')),
                    items: [for (final e in weather.entries) DropdownMenuItem(value: e.key, child: Text(tr('${e.value}')))],
                    onChanged: (v) => w = v ?? w,
                  ),
                ),
                const SizedBox(width: 10),
                SizedBox(width: 100, child: TextField(controller: workers, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Ouvriers')))),
              ]),
              const SizedBox(height: 8),
              TextField(controller: done, maxLines: 3, decoration: InputDecoration(labelText: tr('Travaux réalisés'))),
              const SizedBox(height: 8),
              TextField(controller: inc, maxLines: 2, decoration: InputDecoration(labelText: tr('Incidents, livraisons, visites, réserves'))),
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
    if (ok != true || !context.mounted) return;
    try {
      onChanged(await context.read<Session>().api.post('btp/sites/${site['id']}/logs', {
        'date': isoDate(date), 'weather': w, 'workers': int.tryParse(workers.text.trim()) ?? 0,
        if (done.text.trim().isNotEmpty) 'work_done': done.text.trim(), if (inc.text.trim().isNotEmpty) 'incidents': inc.text.trim(),
      }));
    } catch (e) {
      if (context.mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    final weather = asMap(meta['weather']);
    final logs = [for (final l in site['logs'] as List) asMap(l)];
    return ListView(padding: const EdgeInsets.all(16), children: [
      if (canLog) Align(alignment: Alignment.centerLeft, child: FilledButton.tonalIcon(onPressed: () => _add(context), icon: const Icon(Icons.edit_note), label: Text(tr('Ajouter au journal')))),
      const SizedBox(height: 10),
      if (logs.isEmpty) EmptyState(icon: Icons.menu_book_outlined, title: tr('Journal vide'), message: tr('Chaque jour : météo, effectif, travaux réalisés, incidents et livraisons.')),
      for (final l in logs)
        Card(
          child: Padding(
            padding: const EdgeInsets.all(14),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                Text(tr(dateFr(l['date'])), style: const TextStyle(fontWeight: FontWeight.w800)),
                const SizedBox(width: 10),
                if (l['weather'] != null) StatusChip('${weather[l['weather']] ?? l['weather']}', icon: Icons.wb_sunny_outlined),
                const SizedBox(width: 8),
                StatusChip(tr('${l['workers']} ouvrier(s)'), icon: Icons.groups_outlined),
                const Spacer(),
                if (l['user'] != null) Text(tr('${l['user']}'), style: TextStyle(color: hint, fontSize: 12)),
              ]),
              if (l['work_done'] != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(tr('${l['work_done']}'))),
              if (l['incidents'] != null)
                Padding(
                  padding: const EdgeInsets.only(top: 6),
                  child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    const Icon(Icons.warning_amber_rounded, size: 17, color: YColors.warning),
                    const SizedBox(width: 6),
                    Expanded(child: Text(tr('${l['incidents']}'), style: const TextStyle(color: YColors.warning))),
                  ]),
                ),
            ]),
          ),
        ),
    ]);
  }
}

/// Copie rapide d'un texte (utilisée par les autres écrans au besoin).
void copyText(BuildContext context, String text) {
  Clipboard.setData(ClipboardData(text: text));
  toast(context, tr('Copié.'));
}
