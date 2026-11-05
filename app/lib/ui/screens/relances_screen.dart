import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/filter_bar.dart';
import 'recouvrement_screen.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

const _lateBuckets = {'d30': '1-30 j', 'd60': '31-60 j', 'd90': '61-90 j', 'over90': '+ 90 j'};

String _lateBucket(dynamic days) {
  final d = toDouble(days);
  return d <= 30 ? 'd30' : (d <= 60 ? 'd60' : (d <= 90 ? 'd90' : 'over90'));
}

bool _contactOk(Map<String, dynamic> r, String? v) => switch (v) {
      'paused' => r['paused'] == true,
      'nocontact' => r['email'] == null && r['phone'] == null,
      'ok' => r['paused'] != true && (r['email'] != null || r['phone'] != null),
      _ => true,
    };

const _contactSpec = FilterSpec('contact', 'Contact', {'ok': 'Joignables', 'paused': 'Relances suspendues', 'nocontact': 'Sans contact'}, icon: Icons.contact_phone_outlined);

/// Relances clients : balance âgée, relance d'un client, paliers et textes, historique.
class RelancesScreen extends StatelessWidget {
  const RelancesScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final manage = context.watch<Session>().can('relances.manage');
    final tabs = <(String, Widget)>[
      ('Priorités', const _PrioritiesTab()),
      ('Dossiers de recouvrement', const RecoveryCasesTab()),
      ('Tableau de bord du recouvrement', const RecoveryDashboard()),
      ('Balance âgée', const _AgingTab()),
      ('Historique', const _HistoryTab()),
      if (manage) ('Paliers & messages', const _SettingsTab()),
    ];
    return DefaultTabController(
      length: tabs.length,
      child: Column(children: [
        PageHeader(
          title: tr('Relances & recouvrement'),
          subtitle: tr('Relances automatiques des factures échues, puis dossiers de recouvrement : promesses, échéancier, mise en demeure, huissier'),
          actions: [
            if (manage)
              OutlinedButton.icon(
                onPressed: () async {
                  if (!await confirm(context, tr('Lancer les relances maintenant ?'), tr('Les clients ayant atteint un nouveau palier reçoivent leur message (comme chaque matin à 9 h 15).'))) return;
                  if (!context.mounted) return;
                  try {
                    final r = (await context.read<Session>().api.post('relances/run'))['data'] as Map;
                    if (context.mounted) toast(context, tr('${r['sent']} relance(s) envoyée(s), ${r['failed']} en échec, ${r['skipped']} client(s) ignoré(s).'));
                  } catch (e) {
                    if (context.mounted) toast(context, errorText(e), error: true);
                  }
                },
                icon: const Icon(Icons.send),
                label: Text(tr('Relancer maintenant')),
              ),
          ],
        ),
        TabBar(isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [for (final t in tabs) Tab(text: trn(t.$1))]),
        Expanded(child: TabBarView(physics: const NeverScrollableScrollPhysics(), children: [for (final t in tabs) t.$2])),
      ]),
    );
  }
}

/// Relances prioritaires : les clients dont le règlement relève le plus le point bas de trésorerie prévu.
class _PrioritiesTab extends StatefulWidget {
  const _PrioritiesTab();

  @override
  State<_PrioritiesTab> createState() => _PrioritiesTabState();
}

class _PrioritiesTabState extends State<_PrioritiesTab> {
  static Color _rankColor(Map r) => r['fixes_threshold'] == true
      ? YColors.danger
      : (r['fixes_threshold_pessimistic'] == true || toDouble(r['low_gain']) > 0 || toDouble(r['low_gain_pessimistic']) > 0 ? YColors.warning : YColors.blue);
  Map<String, dynamic>? _d;
  Object? _error;
  String _q = '';
  String _status = '';
  Map<String, String?> _f = {};

  static const _statuses = {'': 'Tous', 'threshold': 'Évitent le seuil', 'gain': 'Améliorent le point bas', 'none': 'Sans effet'};

  static bool _statusOk(Map<String, dynamic> r, String st) {
    final threshold = r['fixes_threshold'] == true || r['fixes_threshold_pessimistic'] == true;
    final gain = toDouble(r['low_gain']) > 0 || toDouble(r['low_gain_pessimistic']) > 0;
    return switch (st) {
      'threshold' => threshold,
      'gain' => gain,
      'none' => !threshold && !gain,
      _ => true,
    };
  }

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('relances/priorities');
      if (mounted) setState(() => (_d = asMap(r['data']), _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_d == null) return _error != null ? OnlineOnlyNotice(error: _error!, onRetry: _load) : const LoadingSkeleton();
    final cur = '${_d!['currency'] ?? context.read<Session>().currency}';
    final hint = Theme.of(context).hintColor;
    final rows = [for (final r in _d!['rows'] as List) asMap(r)];
    final base = [
      for (final (i, r) in rows.indexed)
        if ((_q.isEmpty || '${r['name']}'.toLowerCase().contains(_q)) &&
            (_f['late'] == null || _lateBucket(r['days_late']) == _f['late']) &&
            (_f['level'] == null || '${r['level'] ?? ''}' == _f['level']) &&
            _contactOk(r, _f['contact']))
          (i, r),
    ];
    final shown = base.where((e) => _statusOk(e.$2, _status)).toList();
    final below = _d!['first_below'];
    final belowP = _d!['first_below_pessimistic'];
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(padding: const EdgeInsets.fromLTRB(24, 12, 24, 24), children: [
        Card(
          color: (below == null ? YColors.success : YColors.danger).withValues(alpha: .07),
          child: ListTile(
            leading: Icon(below == null ? Icons.savings_outlined : Icons.warning_amber_rounded, color: below == null ? YColors.success : YColors.danger),
            title: Text(tr('Point bas de trésorerie prévu (réaliste) : ${money(toDouble(_d!['lowest']), cur)}'), style: const TextStyle(fontWeight: FontWeight.w700)),
            subtitle: Text(tr([
              below == null
                  ? 'Le solde réaliste reste au-dessus du seuil (${money(toDouble(_d!['threshold']), cur)}).'
                  : 'Passage sous le seuil (${money(toDouble(_d!['threshold']), cur)}) la semaine du ${dateFr(below)} : relancez d\'abord les clients qui l\'évitent.',
              if (belowP != null) 'Scénario prudent : point bas ${money(toDouble(_d!['lowest_pessimistic']), cur)}, sous le seuil dès la semaine du ${dateFr(belowP)}.',
              'Clients classés par effet de leur règlement sur le point bas, puis par montant échu.',
            ].join(' '))),
          ),
        ),
        const SizedBox(height: 12),
        if (rows.isNotEmpty)
          FilterBar(
            padding: const EdgeInsets.only(bottom: 10),
            search: _q,
            searchHint: 'Client…',
            onSearch: (v) => setState(() => _q = v.toLowerCase()),
            statuses: _statuses,
            status: _status,
            onStatus: (v) => setState(() => _status = v),
            statusCounts: {for (final k in _statuses.keys) k: base.where((e) => _statusOk(e.$2, k)).length},
            filters: [
              const FilterSpec('late', 'Retard', _lateBuckets, icon: Icons.schedule),
              FilterSpec('level', 'Dernier palier', {for (final r in rows) if (r['last_reminder'] != null) '${r['level']}': 'Palier ${r['level']}'}, icon: Icons.stairs_outlined),
              _contactSpec,
            ],
            values: _f,
            onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
            count: shown.length,
            countLabel: 'client',
            onReset: () => setState(() => (_q = '', _status = '', _f = {})),
          ),
        if (rows.isEmpty) EmptyState(icon: Icons.task_alt, title: tr('Aucune échéance en retard'), message: tr('Tous les clients sont à jour.'))
        else if (shown.isEmpty) EmptyState(icon: Icons.search_off, title: tr('Aucun résultat')),
        for (final (i, r) in shown)
          Card(
            child: InkWell(
              onTap: () async {
                await Navigator.of(context).push(MaterialPageRoute(builder: (_) => PartnerDunning(id: '${r['partner_id']}', name: '${r['name']}')));
                _load();
              },
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: Row(children: [
                  CircleAvatar(
                    radius: 16,
                    backgroundColor: _rankColor(r).withValues(alpha: .12),
                    child: Text(tr('${i + 1}'), style: TextStyle(fontWeight: FontWeight.w800, color: _rankColor(r))),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Wrap(spacing: 6, crossAxisAlignment: WrapCrossAlignment.center, children: [
                        Text(tr('${r['name']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                        if (r['fixes_threshold'] == true) StatusChip(tr('Évite le passage sous le seuil'), color: YColors.danger)
                        else if (r['fixes_threshold_pessimistic'] == true) StatusChip(tr('Sécurise le scénario prudent'), color: YColors.warning),
                        if (r['paused'] == true) StatusChip(tr('suspendu'), icon: Icons.pause),
                        if (r['email'] == null && r['phone'] == null) StatusChip(tr('sans contact'), color: YColors.warning),
                      ]),
                      const SizedBox(height: 2),
                      Text(tr([
                        '${r['invoices']} facture(s) · ${r['days_late']} j de retard',
                        if (r['usual_delay'] != null) toDouble(r['usual_delay']) > 0 ? 'paie d\'habitude ${r['usual_delay']} j après l\'échéance' : 'paie d\'habitude à l\'échéance',
                        'attendu le ${dateFr(r['expected_date'])}',
                        if (r['last_reminder'] != null) 'palier ${r['level']} le ${dateFr(r['last_reminder'])}',
                      ].join(' · ')), style: TextStyle(fontSize: 12.5, color: hint)),
                    ]),
                  ),
                  const SizedBox(width: 12),
                  Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
                    Text(tr(money(toDouble(r['overdue']), cur)), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15)),
                    if (toDouble(r['low_gain']) > 0)
                      Text(tr('point bas +${money(toDouble(r['low_gain']), cur)}'), style: const TextStyle(fontSize: 12, color: YColors.success, fontWeight: FontWeight.w600)),
                    if (toDouble(r['low_gain_pessimistic']) > 0)
                      Text(tr('prudent +${money(toDouble(r['low_gain_pessimistic']), cur)}'), style: const TextStyle(fontSize: 12, color: YColors.warning, fontWeight: FontWeight.w600)),
                    if (toDouble(r['low_gain']) <= 0 && toDouble(r['low_gain_pessimistic']) <= 0)
                      Text(tr(r['fixes_threshold_pessimistic'] == true || r['fixes_threshold'] == true ? 'repousse le passage sous le seuil' : 'sans effet sur le point bas'), style: TextStyle(fontSize: 12, color: hint)),
                  ]),
                  const Icon(Icons.chevron_right),
                ]),
              ),
            ),
          ),
      ]),
    );
  }
}

class _AgingTab extends StatefulWidget {
  const _AgingTab();

  @override
  State<_AgingTab> createState() => _AgingTabState();
}

class _AgingTabState extends State<_AgingTab> {
  Map<String, dynamic>? _d;
  Object? _error;
  String _q = '';
  String _status = 'overdue';
  Map<String, String?> _f = {};

  static const _statuses = {'': 'Tous', 'overdue': 'En retard', 'not_due': 'Sans retard', 'over90': '+ 90 j'};

  static bool _statusOk(Map<String, dynamic> r, String st) => switch (st) {
        'overdue' => toDouble(r['overdue']) > 0,
        'not_due' => toDouble(r['overdue']) <= 0,
        'over90' => toDouble(r['over90']) > 0,
        _ => true,
      };

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('relances/aging');
      if (mounted) setState(() => (_d = asMap(r['data']), _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  @override
  Widget build(BuildContext context) {
    final cur = context.watch<Session>().currency;
    if (_d == null) return _error != null ? OnlineOnlyNotice(error: _error!, onRetry: _load) : const LoadingSkeleton();
    final t = asMap(_d!['totals']);
    final all = [for (final r in _d!['rows'] as List) asMap(r)];
    final base = all.where((r) {
      if (_q.isNotEmpty && !'${r['name']}'.toLowerCase().contains(_q)) return false;
      final rem = _f['reminder'];
      if (rem == 'none' && r['last_reminder'] != null) return false;
      if (rem != null && rem != 'none' && '${r['level'] ?? ''}' != rem) return false;
      return _contactOk(r, _f['contact']);
    }).toList();
    final rows = base.where((r) => _statusOk(r, _status)).toList();
    const buckets = [('not_due', 'Non échu', YColors.success), ('d30', '1-30 j', YColors.blue), ('d60', '31-60 j', YColors.warning), ('d90', '61-90 j', Color(0xFFE5701A)), ('over90', '+ 90 j', YColors.danger)];
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(padding: const EdgeInsets.fromLTRB(24, 12, 24, 24), children: [
        Wrap(spacing: 12, runSpacing: 12, children: [
          for (final b in buckets)
            Card(
              child: Container(
                width: 170,
                padding: const EdgeInsets.all(14),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Row(children: [Container(width: 10, height: 10, decoration: BoxDecoration(color: b.$3, shape: BoxShape.circle)), const SizedBox(width: 6), Text(tr(b.$2), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor))]),
                  const SizedBox(height: 4),
                  Text(tr(money(toDouble(t[b.$1]), cur)), style: TextStyle(fontWeight: FontWeight.w800, fontSize: 16, color: b.$1 == 'not_due' ? null : b.$3)),
                ]),
              ),
            ),
        ]),
        const SizedBox(height: 8),
        // Répartition visuelle
        if (toDouble(t['total']) > 0)
          ClipRRect(
            borderRadius: BorderRadius.circular(6),
            child: SizedBox(
              height: 12,
              child: Row(children: [
                for (final b in buckets)
                  if (toDouble(t[b.$1]) > 0) Expanded(flex: (toDouble(t[b.$1]) / toDouble(t['total']) * 1000).round().clamp(1, 1000), child: Container(color: b.$3)),
              ]),
            ),
          ),
        const SizedBox(height: 12),
        Text(tr('Échu ${money(toDouble(t['overdue']), cur)} sur ${money(toDouble(t['total']), cur)}'), style: TextStyle(color: Theme.of(context).hintColor)),
        const SizedBox(height: 10),
        FilterBar(
          padding: const EdgeInsets.only(bottom: 10),
          search: _q,
          searchHint: 'Client…',
          onSearch: (v) => setState(() => _q = v.toLowerCase()),
          statuses: _statuses,
          status: _status,
          onStatus: (v) => setState(() => _status = v),
          statusCounts: {for (final k in _statuses.keys) k: base.where((r) => _statusOk(r, k)).length},
          filters: [
            FilterSpec('reminder', 'Relance', {'none': 'Jamais relancé', for (final r in all) if (r['last_reminder'] != null) '${r['level']}': 'Palier ${r['level']}'}, icon: Icons.stairs_outlined),
            _contactSpec,
          ],
          values: _f,
          onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
          count: rows.length,
          countLabel: 'client',
          onReset: () => setState(() => (_q = '', _status = '', _f = {})),
        ),
        if (rows.isEmpty) EmptyState(icon: Icons.task_alt, title: tr('Aucun impayé en retard'), message: tr('Toutes les factures échues sont réglées.')),
        if (rows.isNotEmpty)
          Card(
            clipBehavior: Clip.antiAlias,
            child: SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: DataTable(
                showCheckboxColumn: false,
                columnSpacing: 20,
                headingTextStyle: TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: Theme.of(context).hintColor),
                columns: [DataColumn(label: Text(tr('Client'))), for (final b in buckets) DataColumn(label: Text(tr(b.$2)), numeric: true), DataColumn(label: Text(tr('Total')), numeric: true), DataColumn(label: Text(tr('Relance')))],
                rows: [
                  for (final r in rows)
                    DataRow(
                      onSelectChanged: (_) async {
                        await Navigator.of(context).push(MaterialPageRoute(builder: (_) => PartnerDunning(id: '${r['partner_id']}', name: '${r['name']}')));
                        _load();
                      },
                      cells: [
                        DataCell(Row(mainAxisSize: MainAxisSize.min, children: [
                          Text(tr('${r['name']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                          if (r['paused'] == true) Padding(padding: EdgeInsets.only(left: 6), child: StatusChip(tr('suspendu'), icon: Icons.pause)),
                          if (r['email'] == null && r['phone'] == null) Padding(padding: EdgeInsets.only(left: 6), child: StatusChip(tr('sans contact'), color: YColors.warning)),
                        ])),
                        for (final b in buckets) DataCell(Text(tr(toDouble(r[b.$1]) == 0 ? '—' : number(toDouble(r[b.$1]))), style: TextStyle(color: toDouble(r[b.$1]) > 0 && b.$1 != 'not_due' ? b.$3 : null))),
                        DataCell(Text(tr(number(toDouble(r['total']))), style: const TextStyle(fontWeight: FontWeight.w700))),
                        DataCell(Text(tr(r['last_reminder'] == null ? '—' : 'Palier ${r['level']} · ${dateFr(r['last_reminder'])}'), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor))),
                      ],
                    ),
                ],
              ),
            ),
          ),
      ]),
    );
  }
}

class PartnerDunning extends StatefulWidget {
  const PartnerDunning({super.key, required this.id, required this.name});
  final String id;
  final String name;

  @override
  State<PartnerDunning> createState() => _PartnerDunningState();
}

class _PartnerDunningState extends State<PartnerDunning> {
  Map<String, dynamic>? _d;
  List<Map<String, dynamic>> _levels = [];
  Object? _error;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final api = context.read<Session>().api;
    try {
      final r = await api.get('relances/partners/${widget.id}');
      final s = await api.get('relances/settings');
      if (mounted) {
        setState(() {
          _d = asMap(r['data']);
          _levels = [for (final l in s['data']['levels'] as List) asMap(l)];
          _error = null;
        });
      }
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _send() async {
    final api = context.read<Session>().api;
    var level = 1;
    final invoices = _d!['invoices'] as List;
    if (invoices.isNotEmpty) {
      final maxDays = invoices.map((i) => (i['days'] as num).toInt()).reduce((a, b) => a > b ? a : b);
      for (var i = 0; i < _levels.length; i++) {
        if (maxDays >= (_levels[i]['days'] as num)) level = i + 1;
      }
    }
    String? preview;
    Future<void> loadPreview(void Function(void Function()) set) async {
      try {
        final r = await api.get('relances/partners/${widget.id}/preview', query: {'level': level});
        set(() => preview = '${r['data']['text']}');
      } catch (e) {
        set(() => preview = errorText(e));
      }
    }

    final ok = await showDialog<bool>(
      context: context,
      builder: (d) => StatefulBuilder(builder: (d, set) {
        if (preview == null) loadPreview(set);
        return YAlertDialog(
          title: Text(tr('Relancer ${widget.name}')),
          content: SizedBox(
            width: 560,
            child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              DropdownButtonFormField<int>(
                initialValue: level,
                decoration: InputDecoration(labelText: tr('Ton du message')),
                items: [for (var i = 0; i < _levels.length; i++) DropdownMenuItem(value: i + 1, child: Text(tr('${_levels[i]['name']} (J+${_levels[i]['days']})')))],
                onChanged: (v) => set(() {
                  level = v ?? 1;
                  preview = null;
                }),
              ),
              const SizedBox(height: 10),
              Container(
                constraints: const BoxConstraints(maxHeight: 320),
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(color: YColors.bg, borderRadius: BorderRadius.circular(8)),
                child: SingleChildScrollView(child: Text(tr(preview ?? 'Aperçu…'), style: const TextStyle(fontSize: 13))),
              ),
            ]),
          ),
          actions: [TextButton(onPressed: () => Navigator.pop(d, false), child: Text(tr('Annuler'))), FilledButton.icon(onPressed: () => Navigator.pop(d, true), icon: const Icon(Icons.send), label: Text(tr('Envoyer')))],
        );
      }),
    );
    if (ok != true || !mounted) return;
    setState(() => _busy = true);
    try {
      final r = (await api.post('relances/partners/${widget.id}/send', {'level': level}))['data'] as Map;
      if (mounted) toast(context, r['status'] == 'sent' ? 'Relance envoyée (${(r['channels'] as Map).keys.join(', ')}).' : 'Échec de l\'envoi : ${(r['channels'] as Map).values.join(' ; ')}', error: r['status'] != 'sent');
      _load();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    final manage = s.can('relances.manage');
    if (_d == null) return Scaffold(appBar: AppBar(title: Text(tr(widget.name))), body: _error != null ? OnlineOnlyNotice(error: _error!, onRetry: _load) : const Center(child: CircularProgressIndicator()));
    final p = asMap(_d!['partner']);
    final inv = [for (final i in _d!['invoices'] as List) asMap(i)];
    final hist = [for (final h in _d!['history'] as List) asMap(h)];
    return Scaffold(
      appBar: AppBar(
        title: Text(tr(widget.name)),
        actions: [
          if (manage && inv.isNotEmpty) FilledButton.icon(onPressed: _busy ? null : _send, icon: const Icon(Icons.send), label: Text(tr('Relancer'))),
          const SizedBox(width: 8),
        ],
      ),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        SectionCard(
          child: Wrap(spacing: 24, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
            Text(tr('E-mail : ${p['email'] ?? '—'}')),
            Text(tr('Téléphone : ${p['phone'] ?? '—'}')),
            if (manage)
              Row(mainAxisSize: MainAxisSize.min, children: [
                Text(tr('Relances automatiques')),
                Switch(
                  value: p['dunning_paused'] != true,
                  onChanged: (v) async {
                    await s.api.post('relances/partners/${widget.id}/pause', {'paused': !v});
                    _load();
                  },
                ),
              ]),
          ]),
        ),
        const SizedBox(height: 12),
        SectionCard(
          title: tr('Factures échues'),
          child: inv.isEmpty
              ? Text(tr('Aucune facture échue.'), style: TextStyle(color: Theme.of(context).hintColor))
              : Column(children: [
                  for (final i in inv)
                    ListTile(
                      dense: true,
                      contentPadding: EdgeInsets.zero,
                      title: Text(tr('${i['number']} du ${dateFr(i['date'])}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                      subtitle: Text(tr('Échue le ${dateFr(i['due_date'])} · ${i['days']} jour(s) de retard${(i['level'] as num) > 0 ? ' · palier ${i['level']} envoyé' : ''}')),
                      trailing: Text(tr(money(toDouble(i['balance']), '${i['currency'] ?? s.currency}')), style: TextStyle(fontWeight: FontWeight.w700, color: (i['days'] as num) > 30 ? YColors.danger : null)),
                    ),
                ]),
        ),
        const SizedBox(height: 12),
        SectionCard(title: tr('Relances envoyées'), child: _HistoryList(rows: hist, showPartner: false)),
      ]),
    );
  }
}

class _HistoryList extends StatelessWidget {
  const _HistoryList({required this.rows, this.showPartner = true});
  final List<Map<String, dynamic>> rows;
  final bool showPartner;

  @override
  Widget build(BuildContext context) {
    if (rows.isEmpty) return Text(tr('Aucune relance.'), style: TextStyle(color: Theme.of(context).hintColor));
    return Column(children: [
      for (final h in rows)
        ListTile(
          dense: true,
          contentPadding: EdgeInsets.zero,
          leading: Icon(h['status'] == 'sent' ? Icons.mark_email_read_outlined : Icons.error_outline, color: h['status'] == 'sent' ? YColors.success : YColors.danger),
          title: Text(tr('${showPartner ? '${h['partner_name']} · ' : ''}${h['level_name']}${h['manual'] == true ? ' (manuelle)' : ''}')),
          subtitle: Text(tr('${dateTimeFr(h['created_at'])} · ${(h['documents'] as List).length} facture(s) · ${(h['channels'] as Map? ?? {}).entries.map((e) => '${e.key} ${e.value == 'ok' ? '✓' : '✗'}').join('  ')}')),
        ),
    ]);
  }
}

class _HistoryTab extends StatefulWidget {
  const _HistoryTab();

  @override
  State<_HistoryTab> createState() => _HistoryTabState();
}

class _HistoryTabState extends State<_HistoryTab> {
  List<Map<String, dynamic>>? _rows;
  Object? _error;
  String _q = '';
  String _status = '';
  Map<String, String?> _f = {};
  FilterPeriod? _period;

  static const _statuses = {'': 'Toutes', 'sent': 'Envoyées', 'failed': 'En échec'};

  static bool _statusOk(Map<String, dynamic> h, String st) => st.isEmpty || (st == 'sent' ? h['status'] == 'sent' : h['status'] != 'sent');

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('relances/history');
      if (mounted) setState(() => _rows = [for (final x in r['data'] as List) asMap(x)]);
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_rows == null) return _error != null ? OnlineOnlyNotice(error: _error!, onRetry: _load) : const LoadingSkeleton();
    final all = _rows!;
    final base = all.where((h) {
      if (_q.isNotEmpty && !'${h['partner_name'] ?? ''} ${h['level_name'] ?? ''}'.toLowerCase().contains(_q)) return false;
      final pa = _f['partner'], lv = _f['level'], md = _f['mode'];
      if (pa != null && '${h['partner_name']}' != pa) return false;
      if (lv != null && '${h['level_name']}' != lv) return false;
      if (md != null && (md == 'manual') != (h['manual'] == true)) return false;
      if (_period != null && !_period!.contains(h['created_at'])) return false;
      return true;
    }).toList();
    final shown = base.where((h) => _statusOk(h, _status)).toList();
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(padding: const EdgeInsets.all(24), children: [
        FilterBar(
          padding: const EdgeInsets.only(bottom: 12),
          search: _q,
          searchHint: 'Client, palier…',
          onSearch: (v) => setState(() => _q = v.toLowerCase()),
          statuses: _statuses,
          status: _status,
          onStatus: (v) => setState(() => _status = v),
          statusCounts: {for (final k in _statuses.keys) k: base.where((h) => _statusOk(h, k)).length},
          filters: [
            FilterSpec('partner', 'Client', filterOptions(all, 'partner_name'), icon: Icons.person_outline),
            FilterSpec('level', 'Palier', filterOptions(all, 'level_name'), icon: Icons.stairs_outlined),
            const FilterSpec('mode', 'Déclenchement', {'auto': 'Automatique', 'manual': 'Manuelle'}, icon: Icons.bolt_outlined),
          ],
          values: _f,
          onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
          showPeriod: true,
          period: _period,
          onPeriod: (v) => setState(() => _period = v),
          count: shown.length,
          countLabel: 'relance',
          onReset: () => setState(() => (_q = '', _status = '', _f = {}, _period = null)),
        ),
        _HistoryList(rows: shown),
      ]),
    );
  }
}

class _SettingsTab extends StatefulWidget {
  const _SettingsTab();

  @override
  State<_SettingsTab> createState() => _SettingsTabState();
}

class _SettingsTabState extends State<_SettingsTab> {
  Map<String, dynamic>? _s;
  final List<Map<String, TextEditingController>> _lv = [];
  final List<Set<String>> _channels = [];
  final _min = TextEditingController();
  bool _enabled = true;
  bool _weekends = true;
  bool _before = true;
  final _beforeDays = TextEditingController(text: '3');
  final _beforeSubject = TextEditingController();
  final _beforeBody = TextEditingController();
  Set<String> _beforeChannels = {'email', 'whatsapp', 'sms'};
  Object? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  void _fill(List levels) {
    _lv.clear();
    _channels.clear();
    for (final l in levels) {
      _lv.add({
        'days': TextEditingController(text: '${l['days']}'),
        'name': TextEditingController(text: '${l['name']}'),
        'subject': TextEditingController(text: '${l['subject'] ?? ''}'),
        'body': TextEditingController(text: '${l['body'] ?? ''}'),
      });
      _channels.add({...((l['channels'] as List?) ?? const ['email', 'whatsapp', 'sms']).map((e) => '$e')});
    }
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('relances/settings');
      if (!mounted) return;
      setState(() {
        _s = asMap(r['data']);
        _enabled = _s!['enabled'] == true;
        _weekends = _s!['skip_weekends'] == true;
        _min.text = number(toDouble(_s!['min_amount'])).replaceAll(RegExp(r'\s'), '');
        _fill(_s!['levels'] as List);
        final b = asMap(_s!['before']);
        _before = b['enabled'] != false;
        _beforeDays.text = '${b['days'] ?? 3}';
        _beforeSubject.text = '${b['subject'] ?? ''}';
        _beforeBody.text = '${b['body'] ?? ''}';
        _beforeChannels = {...((b['channels'] as List?) ?? const ['email', 'whatsapp', 'sms']).map((e) => '$e')};
      });
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _save() async {
    try {
      await context.read<Session>().api.put('relances/settings', {
        'enabled': _enabled,
        'skip_weekends': _weekends,
        'min_amount': toDouble(_min.text),
        'before': {
          'enabled': _before,
          'days': (int.tryParse(_beforeDays.text) ?? 3).clamp(1, 30),
          'subject': _beforeSubject.text.trim(),
          'body': _beforeBody.text,
          'channels': _beforeChannels.toList(),
        },
        'levels': [
          for (var i = 0; i < _lv.length; i++)
            {'days': int.tryParse(_lv[i]['days']!.text) ?? 0, 'name': _lv[i]['name']!.text.trim(), 'subject': _lv[i]['subject']!.text.trim(), 'body': _lv[i]['body']!.text, 'channels': _channels[i].toList()},
        ],
      });
      if (mounted) toast(context, tr('Paramètres enregistrés.'));
      _load();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_s == null) return _error != null ? OnlineOnlyNotice(error: _error!, onRetry: _load) : const LoadingSkeleton();
    return ListView(padding: const EdgeInsets.all(24), children: [
      SectionCard(
        child: Column(children: [
          SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Relances automatiques chaque matin')), value: _enabled, onChanged: (v) => setState(() => _enabled = v)),
          SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Ne pas relancer le week-end')), value: _weekends, onChanged: (v) => setState(() => _weekends = v)),
          TextField(controller: _min, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Ne pas relancer en dessous de (montant restant dû)'))),
        ]),
      ),
      const SizedBox(height: 12),
      SectionCard(
        title: tr('Rappel avant échéance'),
        child: Column(children: [
          SwitchListTile(
            contentPadding: EdgeInsets.zero,
            title: Text(tr('Prévenir le client avant chaque échéance')),
            subtitle: Text(tr('Un message par client, une seule fois par échéance (acompte, solde…), avec le lien de paiement.')),
            value: _before,
            onChanged: (v) => setState(() => _before = v),
          ),
          if (_before) ...[
            Row(children: [
              SizedBox(width: 160, child: TextField(controller: _beforeDays, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Jours avant')))),
              const SizedBox(width: 12),
              Expanded(child: TextField(controller: _beforeSubject, decoration: InputDecoration(labelText: tr('Objet de l\'e-mail')))),
            ]),
            TextField(controller: _beforeBody, maxLines: 6, minLines: 3, decoration: InputDecoration(labelText: tr('Message'))),
            const SizedBox(height: 8),
            Wrap(spacing: 8, children: [
              for (final c in const [('email', 'E-mail'), ('whatsapp', 'WhatsApp'), ('sms', 'SMS')])
                FilterChip(label: Text(tr(c.$2)), selected: _beforeChannels.contains(c.$1), onSelected: (v) => setState(() => v ? _beforeChannels.add(c.$1) : _beforeChannels.remove(c.$1))),
            ]),
          ],
        ]),
      ),
      const SizedBox(height: 8),
      Text(tr('Variables : ${(_s!['variables'] as List).join('  ')}'), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
      const SizedBox(height: 8),
      for (var i = 0; i < _lv.length; i++)
        Padding(
          padding: const EdgeInsets.only(bottom: 12),
          child: SectionCard(
            title: tr('Palier ${i + 1}'),
            trailing: IconButton(tooltip: tr('Retirer'), onPressed: () => setState(() => (_lv.removeAt(i), _channels.removeAt(i))), icon: const Icon(Icons.delete_outline)),
            child: Column(children: [
              Row(children: [
                SizedBox(width: 140, child: TextField(controller: _lv[i]['days'], keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Jours après l\'échéance')))),
                const SizedBox(width: 12),
                Expanded(child: TextField(controller: _lv[i]['name'], decoration: InputDecoration(labelText: tr('Nom du palier')))),
              ]),
              TextField(controller: _lv[i]['subject'], decoration: InputDecoration(labelText: tr('Objet de l\'e-mail'))),
              TextField(controller: _lv[i]['body'], maxLines: 8, minLines: 4, decoration: InputDecoration(labelText: tr('Message'))),
              const SizedBox(height: 8),
              Wrap(spacing: 8, children: [
                for (final c in const [('email', 'E-mail'), ('whatsapp', 'WhatsApp'), ('sms', 'SMS')])
                  FilterChip(label: Text(tr(c.$2)), selected: _channels[i].contains(c.$1), onSelected: (v) => setState(() => v ? _channels[i].add(c.$1) : _channels[i].remove(c.$1))),
              ]),
            ]),
          ),
        ),
      Row(children: [
        if (_lv.length < 6)
          OutlinedButton.icon(
            onPressed: () => setState(() {
              _fill([
                for (var i = 0; i < _lv.length; i++) {'days': _lv[i]['days']!.text, 'name': _lv[i]['name']!.text, 'subject': _lv[i]['subject']!.text, 'body': _lv[i]['body']!.text, 'channels': _channels[i].toList()},
                {'days': 60, 'name': 'Mise en demeure', 'subject': 'Mise en demeure de payer', 'body': 'Bonjour {client},\n\n{factures}\n\nTotal : {total}\n\n{societe}'},
              ]);
            }),
            icon: const Icon(Icons.add),
            label: Text(tr('Ajouter un palier')),
          ),
        TextButton(onPressed: () => setState(() => _fill(_s!['defaults'] as List)), child: Text(tr('Textes par défaut'))),
        const Spacer(),
        FilledButton(onPressed: _save, child: Text(tr('Enregistrer'))),
      ]),
    ]);
  }
}
